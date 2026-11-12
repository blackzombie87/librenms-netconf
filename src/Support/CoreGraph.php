<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Facades\Rrd;
use App\Graphs\Rrd\RrdOptionsBuilder;
use LibreNMS\RRD\Backend\RrdBackendInterface;

/**
 * The core part of a NETCONF graph: the request's GraphParameters, the options they turn into
 * (size, fonts, colours, period, legend) and the rrdtool run, for both graph APIs of LibreNMS -
 * LibreNMS\Data\Graphing\GraphParameters with toRrdOptions() and Rrd::graph() up to 26.10,
 * App\Data\Graphing\GraphParameters with RrdOptionsBuilder and the rrd backend from
 * librenms#21118 on. The graph definition itself comes from GraphBuilder.
 */
final class CoreGraph
{
    private function __construct(private readonly \App\Data\Graphing\GraphParameters|\LibreNMS\Data\Graphing\GraphParameters $params)
    {
    }

    /**
     * @param  array<string, mixed>  $input  the graph request variables (type, from, to, width, height, legend, ...)
     */
    public static function make(array $input): self
    {
        return new self(class_exists(\App\Data\Graphing\GraphParameters::class)
            ? new \App\Data\Graphing\GraphParameters($input)
            : new \LibreNMS\Data\Graphing\GraphParameters($input));
    }

    public function width(): int
    {
        return $this->params->width;
    }

    public function height(): int
    {
        return $this->params->height;
    }

    public function contentType(): string
    {
        return $this->params->imageFormat->value === 'svg' ? 'image/svg+xml' : 'image/png';
    }

    /**
     * @param  list<string>  $definition  the GraphBuilder options (title, DEF, LINE, GPRINT, ...)
     * @return string the image
     */
    public function draw(string $title, array $definition): string
    {
        if ($this->params instanceof \App\Data\Graphing\GraphParameters) {
            $options = [...(new RrdOptionsBuilder($this->params))->build($title, new \App\Data\Graphing\GraphOptions), ...$definition];

            return app(RrdBackendInterface::class)->graph($options, session('preferences.timezone'));
        }

        $options = [...$this->params->toRrdOptions(), ...$definition];
        if (! $this->params->visible('legend')) {
            $options[] = '--no-legend';
        }

        return Rrd::graph($options);
    }
}
