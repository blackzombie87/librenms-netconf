<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Support\CommandGuard;
use SafferIt\LibrenmsNetconf\Support\DeviceSelection;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Support\StatusOverview;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * Plugin pages: device status list, definitions, admin "run command".
 */
class StatusController extends Controller
{
    public function index(Request $request): View
    {
        return view('netconf::status', StatusOverview::data() + [
            'run' => $request->session()->get('netconf_run'),
            'bulk' => $request->session()->get('netconf_bulk'),
        ]);
    }

    /**
     * Enable, disable or reset NETCONF polling for every device of a device group and/or os
     * (plan G7); the same change `lnms netconf:device --group/--os` makes.
     */
    public function bulk(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'group' => 'nullable|string|max:255',
            'os' => 'nullable|string|max:64',
            'enabled' => 'required|in:1,0,inherit',
        ]);

        $result = ['selection' => DeviceSelection::describe($data['group'] ?? null, $data['os'] ?? null), 'action' => $data['enabled'], 'devices' => 0, 'changed' => 0, 'error' => null];
        try {
            $devices = DeviceSelection::resolve($data['group'] ?? null, $data['os'] ?? null);
            $log = DeviceSettings::applyMany($devices, ['enabled' => $data['enabled']]);
            $result['devices'] = $devices->count();
            $result['changed'] = count(array_filter($log));
        } catch (\InvalidArgumentException $e) {
            $result['error'] = $e->getMessage();
        }

        return redirect()->route('netconf.status')->with('netconf_bulk', $result);
    }

    public function definitions(DefinitionLoader $loader): View
    {
        return view('netconf::definitions', [
            'definitions' => $loader->all(),
            'errors' => $loader->errors(),
            'hints' => $loader->hints(),
            'directories' => $loader->directories(),
        ]);
    }

    public function run(Request $request, DeviceCredentials $credentials, TransportFactory $transports): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
            'command' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (($reason = CommandGuard::reject((string) $value)) !== null) {
                    $fail("Command $reason.");
                }
            }],
            'transport' => 'nullable|in:,auto,cli,netconf',
        ]);

        /** @var Device $device */
        $device = Device::query()->findOrFail($data['device_id']);
        $overrides = array_filter(['transport' => $data['transport'] ?? null]);

        $result = ['device' => $device->hostname, 'command' => $data['command'], 'ok' => false, 'xml' => '', 'message' => '', 'duration' => 0.0, 'bytes' => 0];
        $transport = $transports->make($credentials->forDevice($device, $overrides));
        try {
            $reply = $transport->run($data['command']);
            $result['ok'] = true;
            $result['xml'] = mb_strimwidth($reply->pretty(), 0, 200000, "\n… (truncated)");
            $result['duration'] = $reply->duration;
            $result['bytes'] = strlen($reply->raw);
        } catch (TransportException $e) {
            $result['message'] = $e->getMessage();
        } finally {
            // a denied command or a timeout must not leave the SSH session open
            $transport->close();
        }

        return redirect()->route('netconf.status')->with('netconf_run', $result);
    }
}
