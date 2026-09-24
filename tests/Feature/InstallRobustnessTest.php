<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Routing\RouteCollection;
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

        PluginRoutes::warnIfMissing(false);   // no route cache: nothing to warn about
        $this->assertDatabaseMissing('notifications', ['title' => PluginRoutes::NOTIFICATION_TITLE]);

        PluginRoutes::warnIfMissing(true);
        $this->assertDatabaseHas('notifications', ['title' => PluginRoutes::NOTIFICATION_TITLE]);
        $body = (string) \DB::table('notifications')->where('title', PluginRoutes::NOTIFICATION_TITLE)->value('body');
        $this->assertStringContainsString('lnms route:cache', $body);

        // once per process, whatever the hooks ask afterwards
        $this->assertSame(1, \DB::table('notifications')->where('title', PluginRoutes::NOTIFICATION_TITLE)->count());
        PluginRoutes::warnIfMissing(true);
        $this->assertSame(1, \DB::table('notifications')->where('title', PluginRoutes::NOTIFICATION_TITLE)->count());
    }

    public function testNothingIsWarnedAboutWhileTheRoutesAreRegistered(): void
    {
        $this->forgetWarning();

        $this->assertTrue(PluginRoutes::available());
        PluginRoutes::warnIfMissing(true);
        $this->assertDatabaseMissing('notifications', ['title' => PluginRoutes::NOTIFICATION_TITLE]);
    }

    /** Drop every netconf.* route, leaving core's: a cached route file written before the install. */
    private function forgetPluginRoutes(): void
    {
        $kept = new RouteCollection;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'netconf.')) {
                $kept->add($route);
            }
        }

        Route::setRoutes($kept);
    }

    private function forgetWarning(): void
    {
        $warned = new \ReflectionProperty(PluginRoutes::class, 'warned');
        $warned->setValue(null, false);
    }

    private function netconfDevice(): Device
    {
        $device = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($device, ['enabled' => '1']);

        return $device;
    }
}
