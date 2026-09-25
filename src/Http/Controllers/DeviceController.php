<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\DevicePageData;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\TabRegistration;
use SafferIt\LibrenmsNetconf\Support\DevicePage;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * Per-device actions (credential overrides, test connection, discovery / poll now) and the
 * standalone per-device page. The page lives on the NETCONF device tab when the plugin could
 * register it (plan §8 U2); these GET routes then redirect there and only the POST targets
 * stay, because core's device route is GET-only.
 */
class DeviceController extends Controller
{
    public function show(Device $device, string $section = 'status'): View|RedirectResponse
    {
        Gate::authorize('view', $device);

        if (TabRegistration::active()) {
            return redirect(DevicePage::url($device->device_id, $section));
        }

        return view('netconf::device', DevicePageData::section($device, $section));
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'nullable|in:1,0,inherit',
            'evpn_mac' => 'nullable|in:1,0,inherit',
            'queues' => 'nullable|in:1,0,inherit',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'keyfile' => 'nullable|string|max:255',
            'key_passphrase' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'transport' => 'nullable|in:,auto,cli,netconf',
            'clear' => 'nullable|array',
            'clear.*' => 'in:' . implode(',', DeviceSettings::FIELDS),
        ]);

        try {
            $changes = DeviceSettings::apply($device, $data);
        } catch (\InvalidArgumentException $e) {
            return redirect(DevicePage::url($device->device_id, 'edit'))->withErrors([$e->getMessage()]);
        }

        if ($changes !== []) {
            $device->save();
        }

        // the onboarding panel (plan §9.2 W2) posts nothing but enabled=1: return to the status
        // section, where Test connection and Discover now are the next click. The edit form
        // posts its credential fields and returns to itself.
        $quickEnable = ($data['enabled'] ?? null) === '1'
            && array_diff(array_keys($request->except('_token')), ['enabled']) === [];

        return redirect(DevicePage::url($device->device_id, $quickEnable ? 'status' : 'edit'))
            ->with('netconf_result', [
                'type' => 'success',
                'title' => 'Saved',
                'lines' => array_merge(
                    $changes ?: ['no changes'],
                    $quickEnable ? ['Test the connection, then run discovery to create the sensors.'] : [],
                ),
            ]);
    }

    public function test(Device $device, DeviceCredentials $credentials, TransportFactory $transports): RedirectResponse
    {
        $lines = [];
        $ok = false;
        $started = microtime(true);
        $transport = $transports->make($credentials->forDevice($device));
        try {
            $transport->connect();
            $lines[] = sprintf('Connected in %.2fs', microtime(true) - $started);
            foreach ($transport->sessionInfo() as $key => $value) {
                if ($key !== 'capabilities') {
                    $lines[] = "$key: " . (is_scalar($value) ? $value : json_encode($value));
                }
            }
            $reply = $transport->run('show version');
            $lines[] = sprintf('show version: %s %s %s (%d bytes, %.2fs)', $reply->text('host-name') ?? '?', $reply->text('product-model') ?? '?', $reply->text('junos-version') ?? '?', strlen($reply->raw), $reply->duration);
            $ok = true;
        } catch (TransportException $e) {
            $lines[] = $e->getMessage();
        } finally {
            // a timeout or rpc-error after the login must not leave the SSH session open
            $transport->close();
        }

        return redirect(DevicePage::url($device->device_id))
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
            return redirect(DevicePage::url($device->device_id))
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

        return redirect(DevicePage::url($device->device_id))
            ->with('netconf_result', [
                'type' => $report->ok() ? 'success' : 'danger',
                'title' => ($discovery ? 'Discovery' : 'Poll') . ($report->ok() ? ' finished' : ' failed'),
                'lines' => $lines,
            ]);
    }
}
