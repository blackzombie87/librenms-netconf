<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Models\Device;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Data\Graphing\GraphParameters;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;
use SafferIt\LibrenmsNetconf\Support\GraphBuilder;

/**
 * Renders graphs from the plugin's own RRDs (netconf-* and netconf-port-*). Core's
 * graph.php only knows templates under includes/html/graphs, so the plugin draws its
 * own images with the same GraphParameters (size, fonts, colours, period) core uses.
 *
 * Query parameters: from (default -1d), to, width, height, legend=no, graph_type=svg|png.
 */
class GraphController extends Controller
{
    public const MAX_SERIES = 25;

    /** One metric row: all numeric fields, or ?field=<name>. */
    public function metric(Request $request, NetconfMetric $metric): Response
    {
        $device = $this->device($metric->device_id);
        $file = Rrd::name($device->hostname, NetconfMetric::rrdName($metric->definition, $metric->mapping, $metric->metric_index));
        $sources = $metric->dataSources();
        $fields = $this->fields($request, array_keys($sources));

        $builder = new GraphBuilder($this->palette());
        foreach ($fields as $field) {
            $builder->add($file, $field, $field, $sources[$field]);
        }

        return $this->render($request, $builder, sprintf('%s - %s', $device->displayName(), $metric->descr ?: $metric->metric_index), [$file]);
    }

    /** One field across every row of a mapping (one line per index). */
    public function metrics(Request $request): Response
    {
        $data = $request->validate(['device' => 'required|integer', 'definition' => 'required|string', 'mapping' => 'required|string', 'field' => 'required|string']);
        $device = $this->device((int) $data['device']);

        $rows = NetconfMetric::query()->where('device_id', $device->device_id)
            ->where('definition', $data['definition'])->where('mapping', $data['mapping'])->get()
            ->sortBy('metric_index')->take(self::MAX_SERIES);

        $builder = new GraphBuilder($this->palette());
        $files = [];
        foreach ($rows as $row) {
            $sources = $row->dataSources();
            if (! array_key_exists($data['field'], $sources)) {
                continue;
            }
            $files[] = $file = Rrd::name($device->hostname, NetconfMetric::rrdName($row->definition, $row->mapping, $row->metric_index));
            $builder->add($file, $data['field'], $row->metric_index, $sources[$data['field']]);
        }

        return $this->render($request, $builder, sprintf('%s - %s/%s %s', $device->displayName(), $data['definition'], $data['mapping'], $data['field']), $files);
    }

    /** One port metric row (a port and a mapping): all fields or ?field=. */
    public function port(Request $request, NetconfPortMetric $portMetric): Response
    {
        $device = $this->device($portMetric->device_id);
        $port = Port::query()->find($portMetric->port_id);
        $file = Rrd::name($device->hostname, NetconfPortMetric::rrdName($portMetric->port_id, $portMetric->definition, $portMetric->mapping));
        $fields = $this->fields($request, array_keys($portMetric->values ?? []));

        $builder = new GraphBuilder($this->palette());
        foreach ($fields as $field) {
            $builder->add($file, $field, $field, $portMetric->types[$field] ?? 'GAUGE');
        }

        return $this->render($request, $builder, sprintf('%s - %s %s', $device->displayName(), $port !== null ? $port->ifName : 'port ' . $portMetric->port_id, $portMetric->mapping), [$file]);
    }

    /** One field across every port of a mapping. */
    public function ports(Request $request): Response
    {
        $data = $request->validate(['device' => 'required|integer', 'definition' => 'required|string', 'mapping' => 'required|string', 'field' => 'required|string']);
        $device = $this->device((int) $data['device']);

        $rows = NetconfPortMetric::query()->where('netconf_port_metrics.device_id', $device->device_id)
            ->where('definition', $data['definition'])->where('mapping', $data['mapping'])
            ->join('ports', 'ports.port_id', '=', 'netconf_port_metrics.port_id')
            ->orderBy('ports.ifIndex')->limit(self::MAX_SERIES)
            ->get(['netconf_port_metrics.*', 'ports.ifName']);

        $builder = new GraphBuilder($this->palette());
        $files = [];
        foreach ($rows as $row) {
            $values = is_array($row->values) ? $row->values : (array) json_decode((string) $row->values, true);
            $types = is_array($row->types) ? $row->types : (array) json_decode((string) $row->types, true);
            if (! array_key_exists($data['field'], $values)) {
                continue;
            }
            $files[] = $file = Rrd::name($device->hostname, NetconfPortMetric::rrdName((int) $row->port_id, $row->definition, $row->mapping));
            $builder->add($file, $data['field'], (string) $row->ifName, $types[$data['field']] ?? 'GAUGE');
        }

        return $this->render($request, $builder, sprintf('%s - %s/%s %s', $device->displayName(), $data['definition'], $data['mapping'], $data['field']), $files);
    }

    private function device(int $deviceId): Device
    {
        /** @var Device $device */
        $device = Device::query()->findOrFail($deviceId);
        Gate::authorize('view', $device);

        return $device;
    }

    /**
     * @param  list<string>  $available
     * @return list<string>
     */
    private function fields(Request $request, array $available): array
    {
        $wanted = (string) $request->query('field', '');
        if ($wanted === '' || $wanted === 'all') {
            return $available;
        }

        return array_values(array_intersect(explode(',', $wanted), $available));
    }

    /**
     * @return list<string>
     */
    private function palette(): array
    {
        $colours = LibrenmsConfig::get('graph_colours.mixed');

        return is_array($colours) && $colours !== [] ? array_values(array_map('strval', $colours)) : GraphBuilder::COLOURS;
    }

    /**
     * @param  list<string>  $files
     */
    private function render(Request $request, GraphBuilder $builder, string $title, array $files): Response
    {
        $params = new GraphParameters([
            'type' => 'netconf_metric',
            'from' => $request->query('from', '-1d'),
            'to' => $request->query('to'),
            'width' => (int) ($request->query('width') ?? 700),
            'height' => (int) ($request->query('height') ?? 220),
            'legend' => $request->query('legend'),
            'graph_type' => $request->query('graph_type'),
            'title' => 'yes',
        ]);

        $missing = array_filter($files, fn ($f) => ! Rrd::checkRrdExists($f));
        if ($builder->count() === 0 || $missing !== []) {
            return $this->errorImage($params, $builder->count() === 0 ? 'No data' : 'RRD missing');
        }

        $options = [...$params->toRrdOptions(), ...$builder->options($title, $builder->isRate() ? 'per second' : null)];
        if (! $params->visible('legend')) {
            $options[] = '--no-legend';
        }

        try {
            $image = Rrd::graph($options);
        } catch (\Throwable $e) {
            report($e);

            return $this->errorImage($params, 'Error');
        }

        return response($image, 200, [
            'Content-Type' => $params->imageFormat->contentType(),
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    private function errorImage(GraphParameters $params, string $text): Response
    {
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d"><rect width="100%%" height="100%%" fill="#f5f5f5"/><text x="50%%" y="50%%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="14" fill="#888">%s</text></svg>',
            $params->width,
            $params->height,
            htmlspecialchars($text)
        );

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }
}
