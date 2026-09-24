<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\View\Components\Device\PageTabs;
use Illuminate\Support\Facades\DB;
use App\Models\Port;
use SafferIt\LibrenmsNetconf\Hooks\DeviceOverview;
use SafferIt\LibrenmsNetconf\Hooks\PortTab;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\NetconfTab;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\TabRegistration;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The per-device UI after the re-home (plan §8): the overview panel is one summary without
 * value tables (U1); the NETCONF device tab with its sections, who may see which, and the
 * redirects from the old standalone URLs (U2); the port Plugins tab renders numbers and
 * requests graphs only when a fold-out is opened (U3).
 */
final class DeviceUiTest extends LibrenmsTestCase
{
    public function testTheOverviewPanelIsOneSummaryWithoutTables(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->polledDevice();

        $hook = app(DeviceOverview::class);
        $this->assertTrue($hook->authorize($device));
        $html = $hook->handle('netconf', [], $device)->render();

        $this->assertStringContainsString('<strong>NETCONF</strong>', $html);
        $this->assertStringContainsString('Open NETCONF page', $html);
        $this->assertStringContainsString('2 metric rows', $html);
        $this->assertStringContainsString('2 definitions', $html);
        $this->assertStringContainsString('netconf', $html);
        // the status rows are the only table: no metric mapping tables, no ESI-LAG table
        $this->assertSame(1, substr_count($html, '<table'), $html);
        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringNotContainsString('junos-system / mapping', $html);
        $this->assertStringNotContainsString('label-info', $html);   // definitions are a count, not pills
    }

    public function testTheOverviewPanelIsOnlyOfferedForEnabledOrPolledDevices(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $hook = app(DeviceOverview::class);

        $untouched = Device::factory()->create(['os' => 'junos']);
        $this->assertFalse($hook->authorize($untouched));

        $enabled = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($enabled, ['enabled' => '1']);
        $this->assertTrue($hook->authorize($enabled));

        // a viewer without access to the device sees nothing, whatever the device's state
        $this->actingAs(User::factory()->create(['enabled' => 1]));
        $this->assertFalse($hook->authorize($enabled));
    }

    public function testTheTabIsRegisteredBeforeEditAndOnlyOfferedForNetconfDevices(): void
    {
        $this->assertTrue(TabRegistration::active());
        $keys = array_keys(PageTabs::$tabsClasses);
        $this->assertSame(NetconfTab::class, PageTabs::$tabsClasses['netconf']);
        $this->assertSame(array_search('edit', $keys, true) - 1, array_search('netconf', $keys, true), 'netconf sits right before edit');
        $this->assertSame(1, count(array_keys($keys, 'netconf', true)));

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $tab = new NetconfTab;
        // an os no definition matches is never offered a tab; the junos device an admin could
        // enable is (plan §9.2 W1, DeviceOnboardingTest covers that path)
        $this->assertFalse($tab->visible(Device::factory()->create(['os' => 'linux'])));
        $this->assertTrue($tab->visible($this->polledDevice()));
    }

    public function testAdminSeesEverySectionOnTheDeviceTab(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->polledDevice();
        $id = $device->device_id;

        $status = $this->get("/device/$id/netconf")->assertOk()->getContent();
        // the tab bar carries the tab, the section bar is on the page, the sensitive part is not
        $this->assertMatchesRegularExpression('#href="[^"]*/device/' . $id . '/netconf"[^>]*>\s*<i class="fa fa-terminal[^>]*></i>\s*NETCONF#', $status);
        $this->assertStringContainsString('pagemenu-selected', $status);
        $this->assertStringContainsString('4 ok / 1 skipped / 0 failed', $status);
        $this->assertMatchesRegularExpression('/<summary>\d+ matching<\/summary>/', $status);   // the shipped junos definitions
        $this->assertStringContainsString('<code>junos-system</code>', $status);
        $this->assertStringContainsString('Test connection', $status);
        $this->assertStringNotContainsString('name="password"', $status);

        $metrics = $this->get("/device/$id/netconf/metrics?period=-1w")->assertOk()->getContent();
        $this->assertStringContainsString('junos-system / mapping', $metrics);
        $this->assertStringContainsString('Routing engine re1', $metrics);
        $section = substr($metrics, strpos($metrics, 'netconf-mapping'), strpos($metrics, '<script>', strpos($metrics, 'netconf-mapping')) - strpos($metrics, 'netconf-mapping'));
        $this->assertStringNotContainsString('<img', $section);   // graphs load on fold-out
        $this->assertStringContainsString('netconf-graph" data-src=', $section);

        $edit = $this->get("/device/$id/netconf/edit")->assertOk()->getContent();
        $this->assertStringContainsString('name="password"', $edit);
        $this->assertMatchesRegularExpression('#action="[^"]*/plugin/netconf/device/' . $id . '"#', $edit);   // POST targets stay under the plugin prefix

        $this->get("/device/$id/netconf/nope")->assertNotFound();
        $this->get('/device/999999/netconf')->assertNotFound();
    }

    public function testAViewerWithAccessToTheDeviceReadsButCannotEdit(): void
    {
        $device = $this->polledDevice();
        $id = $device->device_id;
        $viewer = User::factory()->create(['enabled' => 1]);
        $viewer->assignRole('user');
        DB::table('devices_perms')->insert(['user_id' => $viewer->user_id, 'device_id' => $id]);
        $this->actingAs($viewer);

        $status = $this->get("/device/$id/netconf")->assertOk()->getContent();
        $this->assertStringContainsString('4 ok / 1 skipped / 0 failed', $status);
        $this->assertStringNotContainsString('Test connection', $status);
        $this->assertStringNotContainsString("/device/$id/netconf/edit", $status);   // no Edit option offered
        $this->assertStringNotContainsString('plugin/netconf/fabric', $status);
        $this->get("/device/$id/netconf/metrics")->assertOk();
        $this->get("/device/$id/netconf/edit")->assertForbidden();

        // a device the viewer may not see: core's device policy, before the tab is reached
        $other = $this->polledDevice();
        $this->get("/device/$other->device_id/netconf")->assertForbidden();

        // the overview panel of the accessible device shows the same summary
        $html = app(DeviceOverview::class)->handle('netconf', [], $device)->render();
        $this->assertStringContainsString("/device/$id/netconf\"", $html);
    }

    public function testTheOldStandaloneUrlsRedirectToTheTab(): void
    {
        $device = $this->polledDevice();
        $id = $device->device_id;

        $this->get("/plugin/netconf/device/$id")->assertRedirect('/login');
        $this->get("/device/$id/netconf")->assertRedirect('/login');

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $this->get("/plugin/netconf/device/$id")->assertRedirect("/device/$id/netconf");
        $this->get("/plugin/netconf/device/$id/metrics")->assertRedirect("/device/$id/netconf/metrics");
        $this->get("/plugin/netconf/device/$id/edit")->assertRedirect("/device/$id/netconf/edit");
        // the status list links to the tab
        $this->assertStringContainsString("/device/$id/netconf\"", $this->get('/plugin/netconf/status')->assertOk()->getContent());
    }

    public function testTheMonthControlDrawsAMonthOnBothMetricPages(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->polledDevice();
        $id = $device->device_id;

        // the device page carries core's own graphs too, so only the plugin's graph URLs count
        $graphUrls = function (string $html): array {
            preg_match_all('/data-src="([^"]*netconf[^"]*)"/', $html, $m);

            return $m[1];
        };

        $metrics = $this->get("/device/$id/netconf/metrics?period=-1mo")->assertOk()->getContent();
        $this->assertStringContainsString('class="text-primary"><strong>month</strong>', $metrics);
        $this->assertNotEmpty($graphUrls($metrics));
        foreach ($graphUrls($metrics) as $url) {
            $this->assertStringContainsString('from=-1mo', $url);
        }

        // and the port tab, which used to pass the query string through unchecked
        $port = Port::factory()->create(['device_id' => $id, 'ifName' => 'et-0/0/2']);
        NetconfPortMetric::query()->create([
            'device_id' => $id,
            'port_id' => $port->port_id,
            'definition' => 'junos-interfaces',
            'mapping' => 'ethernet',
            'values' => ['crc_errors' => 1.0],
            'types' => ['crc_errors' => 'COUNTER'],
            'last_seen' => now(),
        ]);
        $this->get("/device/$id/port/{$port->port_id}?period=-1mo");   // the request the hook reads
        $html = app(PortTab::class)->handle('netconf', $port->fresh(), [])->render();
        $this->assertStringContainsString('class="text-primary"><strong>month</strong>', $html);
        $this->assertNotEmpty($graphUrls($html));
        foreach ($graphUrls($html) as $url) {
            $this->assertStringContainsString('from=-1mo', $url);
        }

        // a token neither page offers falls back to the day and does not reach the graph URLs
        $bad = $this->get("/device/$id/netconf/metrics?period=-1x%3Bzzz")->assertOk()->getContent();
        foreach ($graphUrls($bad) as $url) {
            $this->assertStringContainsString('from=-1d', $url);
            $this->assertStringNotContainsString('zzz', $url);
        }
    }

    public function testThePortPluginsTabShowsNumbersAndNoGraphUntilAFoldOutOpens(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->polledDevice();
        $port = Port::factory()->create(['device_id' => $device->device_id, 'ifName' => 'et-0/0/1']);
        $hook = app(PortTab::class);
        $this->assertFalse($hook->authorize($port));

        NetconfPortMetric::query()->create([
            'device_id' => $device->device_id,
            'port_id' => $port->port_id,
            'definition' => 'junos-interfaces',
            'mapping' => 'ethernet',
            'values' => ['crc_errors' => 3.0, 'fec_ccw' => 0.0, 'input_pause' => 7.0],
            'types' => ['crc_errors' => 'COUNTER', 'fec_ccw' => 'COUNTER', 'input_pause' => 'GAUGE'],
            'last_seen' => now(),
        ]);
        $this->assertTrue($hook->authorize($port->fresh()));
        $html = $hook->handle('netconf', $port->fresh(), [])->render();

        $this->assertStringContainsString('junos-interfaces/ethernet', $html);
        $this->assertStringContainsString('crc_errors*', $html);
        $this->assertStringContainsString('text-warning">3<', $html);   // a counter with errors is highlighted
        $this->assertStringNotContainsString('<img', substr($html, 0, strpos($html, '<script>')));
        // one combined counter graph, one gauge, two individual counters: all deferred
        $this->assertSame(4, substr_count($html, 'netconf-graph" data-src='));
        $this->assertStringContainsString('field=crc_errors%2Cfec_ccw', $html);
        $this->assertStringNotContainsString('<details open', $html);
    }

    private function polledDevice(): Device
    {
        $device = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($device, ['enabled' => '1']);
        NetconfDeviceStatus::query()->create([
            'device_id' => $device->device_id,
            'transport' => 'netconf',
            'definitions' => ['junos-system', 'junos-interfaces'],
            'poll_count' => 3,
            'consecutive_failures' => 0,
            'last_ok' => now(),
            'last_attempt' => now(),
            'last_duration' => 9.3,
            'last_summary' => ['definitions' => 2, 'commands_ok' => 4, 'commands_skipped' => 1, 'commands_failed' => 0, 'sensors' => 5, 'metric_rows' => 2, 'port_rows' => 0],
        ]);
        foreach (['re0', 're1'] as $index) {
            NetconfMetric::query()->create([
                'device_id' => $device->device_id,
                'definition' => 'junos-system',
                'mapping' => 'mapping',
                'metric_index' => $index,
                'descr' => "Routing engine $index",
                'values' => ['cpu' => 12.0, 'memory' => 40.0],
                'types' => ['cpu' => 'GAUGE', 'memory' => 'GAUGE'],
                'last_seen' => now(),
            ]);
        }

        return $device;
    }
}
