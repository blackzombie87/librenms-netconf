<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use SafferIt\LibrenmsNetconf\Hooks\DeviceOverview;
use SafferIt\LibrenmsNetconf\Hooks\Menu;
use SafferIt\LibrenmsNetconf\Hooks\Page;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\NetconfTab;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Support\PluginRoutes;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The install failure of 2026-09-24 (plan §9.1): `lnms plugin:add` leaves LibreNMS's cached
 * route file untouched, so the plugin's hooks are published while `loadRoutesFrom()` is a no-op.
 * The menu entry is rendered from core's own partial on every page, so an unresolvable
 * `route('netconf.status')` took the whole UI down, not just the plugin's pages.
 *
 * The suite always booted an uncached application, which is why it never saw this. Here the
 * plugin's routes are removed from the router after boot, which is the same shape: hooks
 * published, routes missing.
 */
final class InstallRobustnessTest extends LibrenmsTestCase
{
    public function testEveryPageStillRendersWhenThePluginsRoutesAreNotRegistered(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->netconfDevice();
        $id = $device->device_id;

        // normally: menu entry, device tab and overview panel are all there
        $before = $this->get("/device/$id")->assertOk()->getContent();
        $this->assertStringContainsString('/plugin/netconf/status', $before);
        $this->assertStringContainsString("/device/$id/netconf", $before);

        $this->forgetPluginRoutes();
        $this->assertFalse(PluginRoutes::available());

        // the outage: this request used to end in "Route [netconf.status] not defined"
        $after = $this->get("/device/$id")->assertOk()->getContent();
        $this->assertStringNotContainsString('plugin/netconf', $after);
        $this->assertStringNotContainsString("/device/$id/netconf", $after);
        $this->assertStringContainsString('Plugins', $after);   // core's menu is intact

        // and the fleet pages that core renders through the plugin's hooks stay out of the way
        $this->assertFalse(app(Menu::class)->authorize());
        $this->assertFalse(app(Page::class)->authorize());
        $this->assertFalse(app(DeviceOverview::class)->authorize($device));
        $this->assertFalse((new NetconfTab)->visible($device));
    }

    public function testTheMenuViewFallsBackToThePathWhenTheRouteNameIsGone(): void
    {
        $this->forgetPluginRoutes();

        $this->assertSame(url(PluginRoutes::FALLBACK_PATH), PluginRoutes::to('netconf.status'));
        // rendered directly: the view is the last line of defence if a hook ever forgets to ask
        $html = view('netconf::menu')->render();
        $this->assertStringContainsString(url(PluginRoutes::FALLBACK_PATH), $html);
        $this->assertStringContainsString('NETCONF', $html);
    }

    public function testACachedRouteFileWithoutThePluginRaisesANotification(): void
    {
        $this->forgetWarning();
        $this->forgetPluginRoutes();

        PluginRoutes::rememberRouteCache(false);   // no route cache: nothing to warn about
        $this->assertFalse(app(Menu::class)->authorize());
        $this->assertDatabaseMissing('notifications', ['title' => PluginRoutes::NOTIFICATION_TITLE]);

        PluginRoutes::rememberRouteCache(true);
        $this->assertFalse(app(Menu::class)->authorize());
        $this->assertDatabaseHas('notifications', ['title' => PluginRoutes::NOTIFICATION_TITLE]);
        $body = (string) \DB::table('notifications')->where('title', PluginRoutes::NOTIFICATION_TITLE)->value('body');
        $this->assertStringContainsString('lnms route:cache', $body);

        // once per process, whatever the hooks ask afterwards
        $this->assertSame(1, $this->notifications());
        app(Page::class)->authorize();
        app(Menu::class)->authorize();
        $this->assertSame(1, $this->notifications());
    }

    public function testNothingIsWarnedAboutWhileTheRoutesAreRegistered(): void
    {
        $this->forgetWarning();
        PluginRoutes::rememberRouteCache(true);

        $this->assertTrue(PluginRoutes::available());
        $this->assertTrue(PluginRoutes::availableOrWarn());
        $this->assertDatabaseMissing('notifications', ['title' => PluginRoutes::NOTIFICATION_TITLE]);
    }

    /**
     * The 1.2.1 regression (plan §10.9), first half: knowing at boot that the routes come from
     * a cached file must not produce a verdict about them. Laravel requires that file from a
     * booted() callback of its own, and booted() is a FIFO queue that keeps growing while it is
     * drained, so the plugin's own boot-time check ran on either side of the require depending
     * on the install — on a healthy production poller it ran before, and told the admin to run
     * `lnms route:cache` on every request and every poll. Here the routes are gone exactly as
     * they are before that require: remembering the state has to stay quiet.
     */
    public function testTheRouteCacheStateIsRememberedWithoutJudgingTheRoutes(): void
    {
        $this->forgetWarning();
        $this->forgetPluginRoutes();
        $logged = $this->captureLog();

        PluginRoutes::rememberRouteCache(true);

        $this->assertSame([], $logged());
        $this->assertSame(0, $this->notifications());
    }

    /**
     * The second half: routes cached *and* present is a healthy install and every request of one
     * must be silent. 1.2.1's assertions passed against the broken code because they called
     * warnIfMissing() by hand once the routes were there; this drives the hooks instead.
     */
    public function testACachedRouteFileThatCarriesThePluginIsSilentOnEveryRequest(): void
    {
        $this->forgetWarning();
        PluginRoutes::rememberRouteCache(true);

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->netconfDevice();
        $logged = $this->captureLog();

        $page = $this->get('/device/' . $device->device_id)->assertOk()->getContent();
        $this->assertStringContainsString('/plugin/netconf/status', $page);   // the hooks did render
        $this->assertTrue(app(Menu::class)->authorize());

        $this->assertSame([], $logged());
        $this->assertSame(0, $this->notifications());
    }

    /**
     * Everything the plugin logs from here on, as a callable. The production symptom was a log
     * line at request rate, which no notification count catches: the notification is raised once.
     *
     * @return callable(): list<string>
     */
    private function captureLog(): callable
    {
        $messages = [];
        Event::listen(function (MessageLogged $event) use (&$messages) {
            if (str_contains($event->message, 'netconf')) {
                $messages[] = $event->level . ': ' . $event->message;
            }
        });

        return function () use (&$messages): array {
            return $messages;
        };
    }

    private function notifications(): int
    {
        return \DB::table('notifications')->where('title', PluginRoutes::NOTIFICATION_TITLE)->count();
    }

    /** Drop every netconf.* route, leaving core's: a cached route file written before the install. */
    private function forgetPluginRoutes(): void
    {
        Route::setRoutes($this->routes(fn (\Illuminate\Routing\Route $route) => ! str_starts_with((string) $route->getName(), 'netconf.')));
    }

    /**
     * The registered routes that pass the filter, as a plain collection the router accepts back
     * (getRoutes() hands out a CompiledRouteCollection once the routes come from the cache).
     *
     * @param  callable(\Illuminate\Routing\Route): bool  $keep
     */
    private function routes(callable $keep): RouteCollection
    {
        $kept = new RouteCollection;
        foreach (Route::getRoutes() as $route) {
            if ($keep($route)) {
                $kept->add($route);
            }
        }

        return $kept;
    }

    /**
     * Back to "nothing has been warned about yet": the once-per-process flag, and any
     * notification row that outlived the test that wrote it — the suite contains DDL
     * (UninstallTest re-creates the plugin tables), and DDL commits the open transaction.
     */
    private function forgetWarning(): void
    {
        $warned = new \ReflectionProperty(PluginRoutes::class, 'warned');
        $warned->setValue(null, false);
        \DB::table('notifications')->where('title', PluginRoutes::NOTIFICATION_TITLE)->delete();
    }

    private function netconfDevice(): Device
    {
        $device = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($device, ['enabled' => '1']);

        return $device;
    }
}
