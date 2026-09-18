<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * Builds the rrdtool graph DEF/LINE/GPRINT part for one or more data series.
 * Pure PHP so the option list can be unit-tested; the caller prepends the LibreNMS
 * GraphParameters options (size, fonts, colours, period).
 */
class GraphBuilder
{
    /** Fallback palette (LibreNMS graph_colours.mixed). */
    public const COLOURS = ['CC0000', '008C00', '4096EE', '73880A', 'D01F3C', '36393D', 'FF0084', '00FFFF', 'FF6600', '00CC66', '9966FF', 'CC9900', '006699', 'FF3399', '99CC00', 'FF9966'];

    /** @var list<array{file: string, ds: string, label: string, type: string}> */
    private array $series = [];

    /** @var list<string> */
    private array $colours;

    /**
     * @param  list<string>|null  $colours  hex colours without '#'
     */
    public function __construct(?array $colours = null, private int $labelWidth = 24)
    {
        $this->colours = $colours ?: self::COLOURS;
    }

    public function add(string $file, string $ds, string $label, string $type = 'GAUGE'): self
    {
        $this->series[] = ['file' => $file, 'ds' => $ds, 'label' => $label, 'type' => $type];

        return $this;
    }

    public function count(): int
    {
        return count($this->series);
    }

    /**
     * Whether every series is a rate (COUNTER/DERIVE) — then the unit suffix is "/s".
     */
    public function isRate(): bool
    {
        return $this->series !== [] && count(array_filter($this->series, fn ($s) => $s['type'] !== 'GAUGE')) === count($this->series);
    }

    /**
     * @return list<string>
     */
    public function options(string $title, ?string $verticalLabel = null): array
    {
        $options = ['--title', $title];
        if ($verticalLabel !== null) {
            array_push($options, '--vertical-label', $verticalLabel);
        }

        $options[] = 'COMMENT:' . str_pad('', $this->labelWidth + 2) . '     Now      Min      Max      Avg\l';

        foreach ($this->series as $i => $s) {
            $id = 'ds' . $i;
            $colour = $this->colours[$i % count($this->colours)];
            $label = self::safeLabel($s['label'], $this->labelWidth);

            $options[] = "DEF:$id={$s['file']}:{$s['ds']}:AVERAGE";
            $options[] = "DEF:{$id}min={$s['file']}:{$s['ds']}:MIN";
            $options[] = "DEF:{$id}max={$s['file']}:{$s['ds']}:MAX";
            $options[] = "LINE1.25:$id#$colour:$label";
            $options[] = "GPRINT:$id:LAST:%6.2lf%s";
            $options[] = "GPRINT:{$id}min:MIN:%6.2lf%s";
            $options[] = "GPRINT:{$id}max:MAX:%6.2lf%s";
            $options[] = "GPRINT:$id:AVERAGE:%6.2lf%s\\l";
        }

        return $options;
    }

    /**
     * rrdtool legend text: colons must be escaped, width fixed so the GPRINT columns line up.
     */
    public static function safeLabel(string $label, int $width): string
    {
        $label = preg_replace('/[^\x20-\x7E]/u', '?', $label) ?? $label;
        $label = mb_strimwidth($label, 0, $width, '~');

        return str_replace(':', '\\:', str_pad($label, $width));
    }
}
