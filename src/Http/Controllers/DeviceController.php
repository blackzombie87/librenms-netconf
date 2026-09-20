<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Fabric\EsiPeers;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * Per-device page: status, credential overrides, test connection, run discovery / poll.
 */
class DeviceController extends Controller
{
    public function show(Request $request, Device $device, NetconfService $service): View
    {
        Gate::authorize('view', $device);

        return view('netconf::device', $this->pageData($device, $service) + [
            'result' => $request->session()->get('netconf_result'),
        ]);
    }

    public function metrics(Device $device): View
    {
        Gate::authorize('view', $device);

        $metrics = NetconfMetric::query()->where('device_id', $device->device_id)->get()
            ->sortBy(fn (NetconfMetric $m) => $m->definition . '|' . $m->mapping . '|' . $m->metric_index)
            ->groupBy(fn (NetconfMetric $m) => $m->definition . ' / ' . $m->mapping);

        $ports = NetconfPortMetric::query()->where('netconf_port_metrics.device_id', $device->device_id)
            ->join('ports', 'ports.port_id', '=', 'netconf_port_metrics.port_id')
            ->orderBy('ports.ifIndex')
            ->get(['netconf_port_metrics.*', 'ports.ifName', 'ports.ifIndex', 'ports.ifAlias']);

        $period = (string) request()->query('period', '-1d');
        if (! preg_match('/^-\d+[hdwmy]{1,2}$/', $period)) {
            $period = '-1d';
        }

        return view('netconf::metrics', [
            'device' => $device,
            'metrics' => $metrics,
            'ports' => $ports,
            'period' => $period,
            'max_series' => GraphController::MAX_SERIES,
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'nullable|in:1,0,inherit',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'keyfile' => 'nullable|string|max:255',
            'key_passphrase' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'transport' => 'nullable|in:,cli,netconf',
            'clear' => 'nullable|array',
            'clear.*' => 'in:' . implode(',', DeviceSettings::FIELDS),
        ]);

        try {
            $changes = DeviceSettings::apply($device, $data);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('netconf.device', $device->device_id)->withErrors([$e->getMessage()]);
        }

        if ($changes !== []) {
            $device->save();
        }

        return redirect()->route('netconf.device', $device->device_id)
            ->with('netconf_result', ['type' => 'success', 'title' => 'Saved', 'lines' => $changes ?: ['no changes']]);
    }

    public function test(Device $device, DeviceCredentials $credentials, TransportFactory $transports): RedirectResponse
    {
        $lines = [];
        $ok = false;
        $started = microtime(true);
        try {
            $transport = $transports->make($credentials->forDevice($device));
            $transport->connect();
            $lines[] = sprintf('Connected in %.2fs', microtime(true) - $started);
            foreach ($transport->sessionInfo() as $key => $value) {
                if ($key !== 'capabilities') {
                    $lines[] = "$key: " . (is_scalar($value) ? $value : json_encode($value));
                }
            }
            $reply = $transport->run('show version');
            $lines[] = sprintf('show version: %s %s %s (%d bytes, %.2fs)', $reply->text('host-name') ?? '?', $reply->text('product-model') ?? '?', $reply->text('junos-version') ?? '?', strlen($reply->raw), $reply->duration);
            $transport->close();
            $ok = true;
        } catch (TransportException $e) {
            $lines[] = $e->getMessage();
        }

        return redirect()->route('netconf.device', $device->device_id)
            ->with('netconf_result', ['type' => $ok ? 'success' : 'danger', 'title' => $ok ? 'Connection OK' : 'Connection failed', 'lines' => $lines]);
    }

    public function discover(Device $device, NetconfService $service): RedirectResponse
    {
        return $this->runNow($device, $service, true);
    }

    public function poll(Device $device, NetconfService $service): RedirectResponse
    {
        return $this->runNow($device, $service, false);
    }

    private function runNow(Device $device, NetconfService $service, bool $discovery): RedirectResponse
    {
        $reason = $service->skipReason($device, false);
        if ($reason !== null) {
            return redirect()->route('netconf.device', $device->device_id)
                ->with('netconf_result', ['type' => 'warning', 'title' => 'Not run', 'lines' => [$reason]]);
        }

        $report = $service->run($device, app('Datastore'), $discovery);
        $lines = [];
        foreach ($report->toArray() as $key => $value) {
            if ($key === 'errors' || $key === 'definitions') {
                continue;
            }
            $lines[] = "$key: " . (is_scalar($value) ? $value : json_encode($value));
        }
        array_push($lines, ...$report->errors);

        return redirect()->route('netconf.device', $device->device_id)
            ->with('netconf_result', [
                'type' => $report->ok() ? 'success' : 'danger',
                'title' => ($discovery ? 'Discovery' : 'Poll') . ($report->ok() ? ' finished' : ' failed'),
                'lines' => $lines,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(Device $device, NetconfService $service): array
    {
        $credentials = app(DeviceCredentials::class)->forDevice($device);
        $status = $service->status($device);

        $sensorSummary = $device->sensors()->where('poller_type', 'netconf')
            ->selectRaw('sensor_class, count(*) as total')->groupBy('sensor_class')->pluck('total', 'sensor_class')->all();

        return [
            'device' => $device,
            'enabled_attrib' => DeviceSettings::enabledAttrib($device),
            'enabled' => $service->isEnabled($device),
            'overrides' => DeviceSettings::current($device),
            'effective' => $credentials->describe(),
            'definitions' => array_map(fn ($d) => ['name' => $d->name, 'description' => $d->description], $service->matchingDefinitions($device)),
            'skip_reason' => $service->skipReason($device, true),
            'status' => $status->exists ? $status : null,
            'sensor_summary' => $sensorSummary,
            'metric_rows' => NetconfMetric::query()->where('device_id', $device->device_id)->count(),
            'port_rows' => NetconfPortMetric::query()->where('device_id', $device->device_id)->count(),
            'can_admin' => Gate::allows('admin'),
            'esi_rows' => NetconfService::fabricEnabled() ? EsiPeers::forDevice($device->device_id)['rows'] : [],
            'esi_limit' => null,
        ];
    }
}
