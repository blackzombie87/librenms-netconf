<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

/**
 * Orthogonal router for the overview picture: a path from one rect to another that stays out
 * of every other rect, bends as little as it can, and does not run on top of the paths routed
 * before it.
 *
 * The routes are found on a grid whose lines are where a line may run at all:
 *
 *   - several parallel lanes (6 px apart) inside every gap between two neighbouring rects and
 *     inside the margin round the picture, so a channel holds as many lines as it has room for,
 *   - port lines across the rects the edge starts and ends on, and the line one clearance
 *     outside each port, which is where the short stub from the border ends.
 *
 * A* then walks that grid. Every step costs its length, a bend costs a fixed amount, and a
 * grid segment an earlier edge already took costs several times its length, which is what
 * makes parallel edges take different lanes instead of drawing one stroke over another. There
 * is no fallback ring that a path may run round the whole picture on: if the cheapest way is
 * round the outside, the outside has lanes of its own, and it is only taken when the way
 * through is blocked or longer.
 *
 * Integer coordinates and integer costs, a fixed expansion order and a tie-break on insertion
 * order make the result deterministic, because the browser runs the same algorithm after a
 * drag (`topology-eagle.blade.php`) and a reload must not move a line that nobody touched.
 * A change here changes there.
 */
final class GridRouter
{
    /** A hugging line sits this far outside a rect; the same distance keeps a lane off a rect. */
    public const CLEARANCE = 4;

    /** Between the centre-lines of two parallel lanes. */
    public const LANE_PITCH = 6;

    /** Lanes drawn in one gap, however wide it is. */
    public const MAX_LANES = 16;

    /** What turning a corner costs, in px of path. */
    public const BEND = 30;

    /** A grid segment already used by one earlier edge costs this many extra times its length. */
    public const USED_FACTOR = 4;

    /** A segment running along a rect's edge, one clearance outside it, costs this many extra times its length. */
    public const HUG_FACTOR = 4;

    /** Penetration into an obstacle, in px, that is still allowed (a stub touching a border). */
    public const INSIDE_TOL = 1;

    /** Port lines across a rect: at most this many across its width and height. */
    public const PORTS_X = 5;

    public const PORTS_Y = 3;

    /** A port on a top or bottom border keeps this far from a corner of its rect … */
    public const PORT_INSET = 10;

    /** … and one on a left or right border this far, which keeps it clear of a compound's caption. */
    public const PORT_INSET_SIDE = 24;

    /**
     * The grid lines of one picture.
     *
     * @param  list<array{x: int, y: int, w: int, h: int}>  $rects  every rect a line has to miss: compounds and cards that have no compound
     * @param  array{x: int, y: int, w: int, h: int}  $bounds  the routing bounds (the picture with its margin)
     * @return array{xs: list<int>, ys: list<int>}
     */
    public static function lines(array $rects, array $bounds): array
    {
        $xs = [];
        $ys = [];
        $c = self::CLEARANCE;

        // the margin is a channel like any other: four thin virtual rects on the bounds make
        // the pair rule below lay lanes between the outermost rects and the edge of the picture
        $walls = [
            ['x' => $bounds['x'] - 1, 'y' => $bounds['y'], 'w' => 1, 'h' => $bounds['h']],
            ['x' => $bounds['x'] + $bounds['w'], 'y' => $bounds['y'], 'w' => 1, 'h' => $bounds['h']],
            ['x' => $bounds['x'], 'y' => $bounds['y'] - 1, 'w' => $bounds['w'], 'h' => 1],
            ['x' => $bounds['x'], 'y' => $bounds['y'] + $bounds['h'], 'w' => $bounds['w'], 'h' => 1],
        ];
        $all = array_merge($rects, $walls);

        foreach ($all as $i => $a) {
            foreach ($all as $j => $b) {
                if ($i === $j) {
                    continue;
                }
                // b is to the right of a, they share some height, and nothing stands between
                if ($a['x'] + $a['w'] <= $b['x']) {
                    $lo = max($a['y'], $b['y']);
                    $hi = min($a['y'] + $a['h'], $b['y'] + $b['h']);
                    if ($hi > $lo && ! self::between($all, $i, $j, $a['x'] + $a['w'], $b['x'], $lo, $hi, true)) {
                        self::lanes($xs, $a['x'] + $a['w'] + $c, $b['x'] - $c);
                    }
                }
                // b is below a, they share some width, and nothing stands between
                if ($a['y'] + $a['h'] <= $b['y']) {
                    $lo = max($a['x'], $b['x']);
                    $hi = min($a['x'] + $a['w'], $b['x'] + $b['w']);
                    if ($hi > $lo && ! self::between($all, $i, $j, $a['y'] + $a['h'], $b['y'], $lo, $hi, false)) {
                        self::lanes($ys, $a['y'] + $a['h'] + $c, $b['y'] - $c);
                    }
                }
            }
        }

        $xs = array_keys($xs);
        $ys = array_keys($ys);
        sort($xs);
        sort($ys);

        return ['xs' => $xs, 'ys' => $ys];
    }

    /**
     * Route one edge.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @param  list<array{x: int, y: int, w: int, h: int}>  $obstacles  everything the path has to miss
     * @param  array{xs: list<int>, ys: list<int>}  $grid  lines()
     * @param  array<string, list<array{0: int, 1: int}>>  $used  per grid line (`h:{y}` / `v:{x}`) the spans earlier paths took; this call adds its own
     * @param  array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null  $ports  fixed end points (a trunk leaves a card's bottom and lands on a header)
     * @return list<array{0: int, 1: int}> an orthogonal polyline from a point on `$from` to a point on `$to`
     */
    public static function route(array $from, array $to, array $obstacles, array $grid, array &$used, ?array $ports = null): array
    {
        $blockers = array_merge($obstacles, [$from, $to]);

        $starts = self::ends($from, $ports === null ? null : $ports[0], $used);
        $goals = self::ends($to, $ports === null ? null : $ports[1], $used);
        $merged = self::merge($grid['xs'], $grid['ys'], array_merge($starts['xs'], $goals['xs']), array_merge($starts['ys'], $goals['ys']));
        $xs = $merged['xs'];
        $ys = $merged['ys'];
        $xIndex = array_flip($xs);
        $yIndex = array_flip($ys);
        $nx = count($xs);
        $ny = count($ys);
        $total = $nx * $ny * 4;

        $dx = [0, 1, 0, -1];   // up, right, down, left
        $dy = [-1, 0, 1, 0];

        $goalAt = [];
        foreach ($goals['ends'] as $index => $end) {
            $goalAt[$xIndex[$end['node'][0]] * $ny + $yIndex[$end['node'][1]]][] = $index;
        }

        $heap = new class {
            /** @var list<array{0: int, 1: int, 2: int}> */
            private array $items = [];

            public function push(int $f, int $seq, int $state): void
            {
                $i = count($this->items);
                $this->items[] = [$f, $seq, $state];
                while ($i > 0) {
                    $p = ($i - 1) >> 1;
                    if (! $this->less($this->items[$i], $this->items[$p])) {
                        break;
                    }
                    [$this->items[$i], $this->items[$p]] = [$this->items[$p], $this->items[$i]];
                    $i = $p;
                }
            }

            /** @return array{0: int, 1: int, 2: int}|null */
            public function pop(): ?array
            {
                if ($this->items === []) {
                    return null;
                }
                $top = $this->items[0];
                $last = array_pop($this->items);
                if ($this->items !== []) {
                    $this->items[0] = $last;
                    $n = count($this->items);
                    $i = 0;
                    while (true) {
                        $l = 2 * $i + 1;
                        $r = $l + 1;
                        $m = $i;
                        if ($l < $n && $this->less($this->items[$l], $this->items[$m])) {
                            $m = $l;
                        }
                        if ($r < $n && $this->less($this->items[$r], $this->items[$m])) {
                            $m = $r;
                        }
                        if ($m === $i) {
                            break;
                        }
                        [$this->items[$i], $this->items[$m]] = [$this->items[$m], $this->items[$i]];
                        $i = $m;
                    }
                }

                return $top;
            }

            /**
             * @param  array{0: int, 1: int, 2: int}  $a
             * @param  array{0: int, 1: int, 2: int}  $b
             */
            private function less(array $a, array $b): bool
            {
                return $a[0] < $b[0] || ($a[0] === $b[0] && $a[1] < $b[1]);
            }
        };

        // state = (ix * ny + iy) * 4 + the direction we arrived in; states from $total up are
        // "arrived at goal end n" and end the search
        $cost = [];
        $came = [];
        $startOf = [];
        $closed = [];
        $seq = 0;
        $heuristic = fn (int $x, int $y): int => self::distance($x, $y, $to);
        foreach ($starts['ends'] as $index => $end) {
            $state = ($xIndex[$end['node'][0]] * $ny + $yIndex[$end['node'][1]]) * 4 + $end['dir'];
            if (! isset($cost[$state]) || $end['cost'] < $cost[$state]) {
                $cost[$state] = $end['cost'];
                $came[$state] = -1;   // no parent: this is a start
                $startOf[$state] = $index;
                $heap->push($end['cost'] + $heuristic($end['node'][0], $end['node'][1]), $seq++, $state);
            }
        }

        // per grid segment, once per route: whether a rect is in the way, and how much of it runs
        // along a rect's edge (the two together are most of what a step costs to evaluate)
        $memo = [];
        $inspect = function (int $axis, int $line, int $a, int $b) use (&$memo, $blockers): array {
            $key = $axis . ':' . $line . ':' . $a . ':' . $b;

            return $memo[$key] ??= [
                self::segmentBlocked($axis, $line, $a, $b, $blockers),
                self::hugging($axis, $line, $a, $b, $blockers),
            ];
        };

        $final = null;
        $finalEnd = null;
        $expanded = 0;
        while (($item = $heap->pop()) !== null) {
            $state = $item[2];
            if (isset($closed[$state])) {
                continue;
            }
            $closed[$state] = true;
            if ($state >= $total) {
                $finalEnd = $goals['ends'][$state - $total];
                $final = $came[$state];
                break;
            }
            if (++$expanded > 60000) {
                break;
            }
            $node = intdiv($state, 4);
            $dir = $state % 4;
            $g = $cost[$state];
            $ix = intdiv($node, $ny);
            $iy = $node % $ny;

            foreach ($goalAt[$node] ?? [] as $index) {
                $end = $goals['ends'][$index];
                $ng = $g + $end['cost'] + ($end['dir'] === ($dir + 2) % 4 ? 0 : self::BEND);
                $goalState = $total + $index;
                if (! isset($cost[$goalState]) || $ng < $cost[$goalState]) {
                    $cost[$goalState] = $ng;
                    $came[$goalState] = $state;
                    $heap->push($ng, $seq++, $goalState);
                }
            }

            for ($d = 0; $d < 4; $d++) {
                if ($d === ($dir + 2) % 4) {
                    continue;   // no U-turn
                }
                $jx = $ix + $dx[$d];
                $jy = $iy + $dy[$d];
                if ($jx < 0 || $jy < 0 || $jx >= $nx || $jy >= $ny) {
                    continue;
                }
                if ($d % 2 === 0) {
                    $a = min($ys[$iy], $ys[$jy]);
                    $b = max($ys[$iy], $ys[$jy]);
                    [$wall, $hug] = $inspect(1, $xs[$ix], $a, $b);
                    if ($wall) {
                        continue;
                    }
                    $shared = self::shared($used, 'v:' . $xs[$ix], $a, $b) + self::HUG_FACTOR * $hug;
                } else {
                    $a = min($xs[$ix], $xs[$jx]);
                    $b = max($xs[$ix], $xs[$jx]);
                    [$wall, $hug] = $inspect(0, $ys[$iy], $a, $b);
                    if ($wall) {
                        continue;
                    }
                    $shared = self::shared($used, 'h:' . $ys[$iy], $a, $b) + self::HUG_FACTOR * $hug;
                }
                $ng = $g + ($b - $a) + self::USED_FACTOR * $shared + ($d === $dir ? 0 : self::BEND);
                $next = ($jx * $ny + $jy) * 4 + $d;
                if (! isset($cost[$next]) || $ng < $cost[$next]) {
                    $cost[$next] = $ng;
                    $came[$next] = $state;
                    $heap->push($ng + $heuristic($xs[$jx], $ys[$jy]), $seq++, $next);
                }
            }
        }

        if ($final === null) {
            return self::fallback($from, $to, $ports);
        }

        // walk back to the start end, collecting grid points
        $points = [];
        $state = $final;
        while (true) {
            $node = intdiv($state, 4);
            $points[] = [$xs[intdiv($node, $ny)], $ys[$node % $ny]];
            $parent = $came[$state];
            if ($parent < 0) {
                $startEnd = $starts['ends'][$startOf[$state]];
                break;
            }
            $state = $parent;
        }
        $path = self::simplify(array_merge([$startEnd['port']], array_reverse($points), [$finalEnd['port']]));
        // what this path took, so the next edge pays to share it
        for ($i = 1; $i < count($path); $i++) {
            if ($path[$i][1] === $path[$i - 1][1] && $path[$i][0] !== $path[$i - 1][0]) {
                $used['h:' . $path[$i][1]][] = [min($path[$i][0], $path[$i - 1][0]), max($path[$i][0], $path[$i - 1][0])];
            } elseif ($path[$i][0] === $path[$i - 1][0] && $path[$i][1] !== $path[$i - 1][1]) {
                $used['v:' . $path[$i][0]][] = [min($path[$i][1], $path[$i - 1][1]), max($path[$i][1], $path[$i - 1][1])];
            }
        }

        return $path;
    }

    /**
     * How many px of [a, b] on one grid line earlier paths already took, counting each path.
     *
     * @param  array<string, list<array{0: int, 1: int}>>  $used
     */
    private static function shared(array $used, string $line, int $a, int $b): int
    {
        $sum = 0;
        foreach ($used[$line] ?? [] as [$lo, $hi]) {
            $overlap = min($b, $hi) - max($a, $lo);
            if ($overlap > 0) {
                $sum += $overlap;
            }
        }

        return $sum;
    }

    /**
     * Lanes inside one gap: up to MAX_LANES lines LANE_PITCH apart, centred in the gap, or the
     * one centre line when it is narrower than a pitch.
     *
     * @param  array<int, true>  $lines
     */
    private static function lanes(array &$lines, int $start, int $end): void
    {
        if ($end < $start) {
            return;
        }
        $n = min(self::MAX_LANES, intdiv($end - $start, self::LANE_PITCH) + 1);
        $first = $start + intdiv($end - $start - ($n - 1) * self::LANE_PITCH, 2);
        for ($i = 0; $i < $n; $i++) {
            $lines[$first + $i * self::LANE_PITCH] = true;
        }
    }

    /**
     * Whether a third rect stands in the gap between rect $i and rect $j along the axis, in the
     * range of cross-axis coordinates the two share.
     *
     * @param  list<array{x: int, y: int, w: int, h: int}>  $rects
     */
    private static function between(array $rects, int $i, int $j, int $from, int $to, int $lo, int $hi, bool $horizontal): bool
    {
        foreach ($rects as $k => $t) {
            if ($k === $i || $k === $j) {
                continue;
            }
            if ($horizontal) {
                if ($t['x'] < $to && $t['x'] + $t['w'] > $from && $t['y'] < $hi && $t['y'] + $t['h'] > $lo) {
                    return true;
                }
            } elseif ($t['y'] < $to && $t['y'] + $t['h'] > $from && $t['x'] < $hi && $t['x'] + $t['w'] > $lo) {
                return true;
            }
        }

        return false;
    }

    /**
     * The end points an edge may leave a rect by (or one fixed port): for each, the port on
     * the rect's border, the grid node one clearance outside it, and the direction of the stub.
     * The cost of an end is the stub, dearer where an earlier path already took the same stub.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{0: int, 1: int}|null  $fixed
     * @param  array<string, list<array{0: int, 1: int}>>  $used
     * @return array{ends: list<array{port: array{0: int, 1: int}, node: array{0: int, 1: int}, dir: int, cost: int}>, xs: list<int>, ys: list<int>}
     */
    private static function ends(array $rect, ?array $fixed, array $used): array
    {
        $c = self::CLEARANCE;
        $ports = [];
        if ($fixed !== null) {
            $ports[] = [$fixed, self::sideOf($rect, $fixed)];
        } else {
            foreach (self::spread($rect['x'], $rect['w'], self::PORTS_X, self::PORT_INSET) as $x) {
                $ports[] = [[$x, $rect['y']], 'top'];
                $ports[] = [[$x, $rect['y'] + $rect['h']], 'bottom'];
            }
            foreach (self::spread($rect['y'], $rect['h'], self::PORTS_Y, self::PORT_INSET_SIDE) as $y) {
                $ports[] = [[$rect['x'], $y], 'left'];
                $ports[] = [[$rect['x'] + $rect['w'], $y], 'right'];
            }
        }

        $extraX = [];
        $extraY = [];
        $ends = [];
        foreach ($ports as [$port, $side]) {
            [$px, $py] = $port;
            [$node, $dir] = match ($side) {
                'top' => [[$px, $py - $c], 0],
                'right' => [[$px + $c, $py], 1],
                'bottom' => [[$px, $py + $c], 2],
                default => [[$px - $c, $py], 3],
            };
            $extraX[$px] = true;
            $extraY[$py] = true;
            $extraX[$node[0]] = true;
            $extraY[$node[1]] = true;
            $shared = $dir % 2 === 0
                ? self::shared($used, 'v:' . $px, min($py, $node[1]), max($py, $node[1]))
                : self::shared($used, 'h:' . $py, min($px, $node[0]), max($px, $node[0]));
            $ends[] = ['port' => $port, 'node' => $node, 'dir' => $dir, 'cost' => $c + self::USED_FACTOR * $shared];
        }

        return ['ends' => $ends, 'xs' => array_keys($extraX), 'ys' => array_keys($extraY)];
    }

    /**
     * @param  list<int>  $xs
     * @param  list<int>  $ys
     * @param  list<int>  $moreX
     * @param  list<int>  $moreY
     * @return array{xs: list<int>, ys: list<int>}
     */
    private static function merge(array $xs, array $ys, array $moreX, array $moreY): array
    {
        $x = array_unique(array_merge($xs, $moreX));
        $y = array_unique(array_merge($ys, $moreY));
        sort($x);
        sort($y);

        return ['xs' => $x, 'ys' => $y];
    }

    /**
     * @return list<int>
     */
    private static function spread(int $start, int $length, int $max, int $inset): array
    {
        $span = $length - 2 * $inset;
        $n = max(1, min($max, intdiv(max(0, $span), 28) + 1));
        if ($n === 1) {
            return [$start + intdiv($length, 2)];
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $start + $inset + intdiv($span * $i, $n - 1);
        }

        return $out;
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{0: int, 1: int}  $p
     */
    private static function sideOf(array $rect, array $p): string
    {
        return match (true) {
            $p[0] >= $rect['x'] + $rect['w'] => 'right',
            $p[0] <= $rect['x'] => 'left',
            $p[1] >= $rect['y'] + $rect['h'] => 'bottom',
            default => 'top',
        };
    }

    /** Manhattan distance from a point to a rect, plus the clearance of the final stub. */
    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     */
    private static function distance(int $x, int $y, array $rect): int
    {
        $dx = max($rect['x'] - $x, 0, $x - ($rect['x'] + $rect['w']));
        $dy = max($rect['y'] - $y, 0, $y - ($rect['y'] + $rect['h']));

        return $dx + $dy;
    }

    /**
     * How much of a grid segment runs along the edge of a rect, one clearance outside it: the
     * line a stub ends on is such a line, and a path that follows it for long is a line drawn
     * on the border of a box instead of in the channel beside it.
     *
     * @param  list<array{x: int, y: int, w: int, h: int}>  $rects
     */
    private static function hugging(int $axis, int $line, int $a, int $b, array $rects): int
    {
        $c = self::CLEARANCE;
        $sum = 0;
        foreach ($rects as $r) {
            if ($axis === 0) {
                if ($line === $r['y'] - $c || $line === $r['y'] + $r['h'] + $c) {
                    $sum += max(0, min($b, $r['x'] + $r['w']) - max($a, $r['x']));
                }
            } elseif ($line === $r['x'] - $c || $line === $r['x'] + $r['w'] + $c) {
                $sum += max(0, min($b, $r['y'] + $r['h']) - max($a, $r['y']));
            }
        }

        return $sum;
    }

    /**
     * Whether a straight grid segment passes through the inside of any rect. Axis 0 is
     * horizontal at y = $line from $a to $b, axis 1 vertical at x = $line.
     *
     * @param  list<array{x: int, y: int, w: int, h: int}>  $rects
     */
    private static function segmentBlocked(int $axis, int $line, int $a, int $b, array $rects): bool
    {
        $t = self::INSIDE_TOL;
        foreach ($rects as $r) {
            if ($axis === 0) {
                if ($line > $r['y'] + $t && $line < $r['y'] + $r['h'] - $t && $a < $r['x'] + $r['w'] - $t && $b > $r['x'] + $t) {
                    return true;
                }
            } elseif ($line > $r['x'] + $t && $line < $r['x'] + $r['w'] - $t && $a < $r['y'] + $r['h'] - $t && $b > $r['y'] + $t) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the points that lie on a straight run.
     *
     * @param  list<array{0: int, 1: int}>  $points
     * @return list<array{0: int, 1: int}>
     */
    private static function simplify(array $points): array
    {
        $out = [];
        foreach ($points as $p) {
            $n = count($out);
            if ($n > 0 && $out[$n - 1] === $p) {
                continue;
            }
            if ($n > 1) {
                $a = $out[$n - 2];
                $b = $out[$n - 1];
                if (($a[0] === $b[0] && $b[0] === $p[0]) || ($a[1] === $b[1] && $b[1] === $p[1])) {
                    $out[$n - 1] = $p;

                    continue;
                }
            }
            $out[] = $p;
        }

        return $out;
    }

    /**
     * When the grid has no way through (every side of an end is walled in): two bends between
     * the facing sides, ignoring the obstacles. Total, so a caller always gets a polyline; a
     * test that expected a clean path fails on it.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @param  array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null  $ports
     * @return list<array{0: int, 1: int}>
     */
    private static function fallback(array $from, array $to, ?array $ports): array
    {
        [$ps, $pd] = $ports ?? self::facing($from, $to);
        $mid = (int) round(($ps[1] + $pd[1]) / 2);

        return self::simplify([$ps, [$ps[0], $mid], [$pd[0], $mid], $pd]);
    }

    /**
     * The two side midpoints facing each other on whichever axis the centres are further apart.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    public static function facing(array $from, array $to): array
    {
        $dx = ($to['x'] + $to['w'] / 2) - ($from['x'] + $from['w'] / 2);
        $dy = ($to['y'] + $to['h'] / 2) - ($from['y'] + $from['h'] / 2);
        $mid = fn (array $r, string $which): array => match ($which) {
            'right' => [$r['x'] + $r['w'], (int) round($r['y'] + $r['h'] / 2)],
            'left' => [$r['x'], (int) round($r['y'] + $r['h'] / 2)],
            'bottom' => [(int) round($r['x'] + $r['w'] / 2), $r['y'] + $r['h']],
            default => [(int) round($r['x'] + $r['w'] / 2), $r['y']],
        };
        if (abs($dx) >= abs($dy)) {
            return $dx >= 0 ? [$mid($from, 'right'), $mid($to, 'left')] : [$mid($from, 'left'), $mid($to, 'right')];
        }

        return $dy >= 0 ? [$mid($from, 'bottom'), $mid($to, 'top')] : [$mid($from, 'top'), $mid($to, 'bottom')];
    }
}
