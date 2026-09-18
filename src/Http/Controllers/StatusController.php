<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
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
        ]);
    }

    public function definitions(DefinitionLoader $loader): View
    {
        return view('netconf::definitions', [
            'definitions' => $loader->all(),
            'errors' => $loader->errors(),
            'directories' => $loader->directories(),
        ]);
    }

    public function run(Request $request, DeviceCredentials $credentials, TransportFactory $transports): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
            'command' => 'required|string|max:500|regex:/^show\s/i',
            'transport' => 'nullable|in:,cli,netconf',
        ]);

        /** @var Device $device */
        $device = Device::query()->findOrFail($data['device_id']);
        $overrides = array_filter(['transport' => $data['transport'] ?? null]);

        $result = ['device' => $device->hostname, 'command' => $data['command'], 'ok' => false, 'xml' => '', 'message' => '', 'duration' => 0.0, 'bytes' => 0];
        try {
            $transport = $transports->make($credentials->forDevice($device, $overrides));
            $reply = $transport->run($data['command']);
            $transport->close();
            $result['ok'] = true;
            $result['xml'] = mb_strimwidth($reply->pretty(), 0, 200000, "\n… (truncated)");
            $result['duration'] = $reply->duration;
            $result['bytes'] = strlen($reply->raw);
        } catch (TransportException $e) {
            $result['message'] = $e->getMessage();
        }

        return redirect()->route('netconf.status')->with('netconf_run', $result);
    }
}
