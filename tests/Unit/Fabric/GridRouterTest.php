<?php

use SafferIt\LibrenmsNetconf\Fabric\View\EagleLayout;
use SafferIt\LibrenmsNetconf\Fabric\View\GridRouter;

/**
 * A picture-shaped set of rects: two gateway boxes on top, a 3 + 2 grid of site boxes below.
 *
 * @return array<string, array{x: int, y: int, w: int, h: int}>
 */
function gridRects(): array
{
    return [
        'gw1' => ['x' => 280, 'y' => 24, 'w' => 237, 'h' => 98],
        'gw2' => ['x' => 565, 'y' => 24, 'w' => 331, 'h' => 98],
        'a' => ['x' => 24, 'y' => 218, 'w' => 344, 'h' => 178],
        'b' => ['x' => 416, 'y' => 218, 'w' => 344, 'h' => 98],
        'c' => ['x' => 808, 'y' => 218, 'w' => 344, 'h' => 98],
        'd' => ['x' => 220, 'y' => 444, 'w' => 344, 'h' => 98],
        'e' => ['x' => 612, 'y' => 444, 'w' => 344, 'h' => 98],
    ];
}

/**
 * @param  array<string, array{x: int, y: int, w: int, h: int}>  $rects
 * @return array<string, mixed>
 */
function gridContext(array $rects): array
{
    $groups = [];
    foreach ($rects as $key => $rect) {
        $groups[] = ['key' => $key] + $rect;
    }

    return EagleLayout::routeContext($groups, [], 1176, 566);
}

/**
 * @param  array<string, array{x: int, y: int, w: int, h: int}>  $rects
 * @return list<array{x: int, y: int, w: int, h: int}>
 */
function gridObstacles(array $rects, string ...$except): array
{
    return array_values(array_filter($rects, fn (string $key) => ! in_array($key, $except, true), ARRAY_FILTER_USE_KEY));
}

/** @param list<array{0: int, 1: int}> $points */
function gridOrthogonal(array $points): bool
{
    for ($i = 1; $i < count($points); $i++) {
        if ($points[$i][0] !== $points[$i - 1][0] && $points[$i][1] !== $points[$i - 1][1]) {
            return false;
        }
    }

    return true;
}

it('routes between two boxes orthogonally and never through a third', function () {
    $rects = gridRects();
    $ctx = gridContext($rects);

    foreach ([['gw1', 'a'], ['gw1', 'e'], ['a', 'e'], ['gw2', 'd'], ['c', 'd']] as [$from, $to]) {
        $points = EagleLayout::route($rects[$from], $rects[$to], gridObstacles($rects, $from, $to), $ctx, "edge:$from|$to")['points'];

        expect(gridOrthogonal($points))->toBeTrue("$from → $to is orthogonal")
            ->and(EagleLayout::pathClean($points, gridObstacles($rects, $from, $to)))->toBeTrue("$from → $to misses every other box")
            ->and($points[0])->not->toBe(end($points));
    }
});

it('leaves a box by a side that faces the other one and takes the short way', function () {
    $rects = gridRects();
    $ctx = gridContext($rects);

    $points = EagleLayout::route($rects['a'], $rects['b'], gridObstacles($rects, 'a', 'b'), $ctx, 'edge:a|b')['points'];

    // side by side: out of a's right side, into b's left side, across the 48 px between them
    expect($points[0][0])->toBe($rects['a']['x'] + $rects['a']['w'])
        ->and(end($points)[0])->toBe($rects['b']['x'])
        ->and(count($points))->toBeLessThanOrEqual(4);
});

it('gives parallel edges between the same two boxes lanes of their own', function () {
    $rects = gridRects();
    $ctx = gridContext($rects);

    $verticals = [];
    foreach (['one', 'two', 'three', 'four'] as $name) {
        $points = EagleLayout::route($rects['a'], $rects['b'], gridObstacles($rects, 'a', 'b'), $ctx, "edge:$name")['points'];
        // the leg in the channel between the two boxes
        foreach ($points as $i => $p) {
            if ($i > 0 && $p[0] === $points[$i - 1][0] && abs($p[1] - $points[$i - 1][1]) > 40) {
                $verticals[] = $p[0];
            }
        }
    }

    // either different lanes, or different sides: never four strokes on one pixel column
    expect(count(array_unique($verticals)))->toBe(count($verticals));
});

it('never routes round the outside of the picture when a channel is free', function () {
    $rects = gridRects();
    $ctx = gridContext($rects);

    $points = EagleLayout::route($rects['gw1'], $rects['d'], gridObstacles($rects, 'gw1', 'd'), $ctx, 'edge:gw1|d')['points'];
    $ring = $ctx['bounds'];

    foreach ($points as $p) {
        expect($p[0])->toBeGreaterThan($ring['x'] + 12)->and($p[0])->toBeLessThan($ring['x'] + $ring['w'] - 12);
    }
});

it('honours fixed end points, the way a trunk leaves a card and lands on a header', function () {
    $rects = gridRects();
    $ctx = gridContext($rects);
    $card = ['x' => 360, 'y' => 40, 'w' => 156, 'h' => 58];
    $ports = [[438, 98], [596, 218]];

    $points = EagleLayout::route($card, $rects['b'], gridObstacles($rects, 'b'), $ctx, 'edge:trunk', $ports)['points'];

    expect($points[0])->toBe([438, 98])
        ->and(end($points))->toBe([596, 218])
        ->and(gridOrthogonal($points))->toBeTrue();
});

it('is deterministic: the same requests in the same order give the same paths', function () {
    $rects = gridRects();
    $run = function () use ($rects) {
        $ctx = gridContext($rects);
        $out = [];
        foreach ([['gw1', 'a'], ['gw1', 'c'], ['gw2', 'a'], ['gw2', 'e'], ['b', 'd'], ['a', 'e']] as [$from, $to]) {
            $out[] = EagleLayout::route($rects[$from], $rects[$to], gridObstacles($rects, $from, $to), $ctx, "edge:$from|$to")['points'];
        }

        return $out;
    };

    expect($run())->toBe($run());
});

it('answers with a polyline even when a box is walled in', function () {
    $from = ['x' => 100, 'y' => 100, 'w' => 40, 'h' => 40];
    $to = ['x' => 400, 'y' => 100, 'w' => 40, 'h' => 40];
    // four obstacles touching its sides leave no stub free
    $walls = [['x' => 60, 'y' => 60, 'w' => 120, 'h' => 36], ['x' => 60, 'y' => 144, 'w' => 120, 'h' => 36], ['x' => 56, 'y' => 60, 'w' => 40, 'h' => 120], ['x' => 144, 'y' => 60, 'w' => 40, 'h' => 120]];
    $used = [];

    $points = GridRouter::route($from, $to, $walls, GridRouter::lines([$from, $to], ['x' => 0, 'y' => 0, 'w' => 500, 'h' => 240]), $used);

    expect(count($points))->toBeGreaterThanOrEqual(2);
});

/**
 * The browser runs the same router after a drag. The two have to agree on every path, or a
 * reload would move a line nobody touched: this evaluates the router block of the Blade file
 * in node and compares it with the PHP one on a sequence of requests that share their `used`
 * spans, which is where a difference in cost arithmetic would show first.
 */
it('draws the same paths in the browser script as on the server', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }
    $blade = (string) file_get_contents(__DIR__ . '/../../../resources/views/fabric/topology-eagle.blade.php');
    if (preg_match('#/\* BEGIN grid-router \*/(.*)/\* END grid-router \*/#s', $blade, $m) !== 1) {
        $this->fail('the router block is not marked in the Blade file');
    }

    $rects = gridRects();
    $ctx = gridContext($rects);
    $loose = ['spine' => ['x' => 500, 'y' => 24, 'w' => 156, 'h' => 58]];
    $requests = [];
    $pairs = [['gw1', 'a'], ['gw1', 'b'], ['gw1', 'c'], ['gw1', 'd'], ['gw1', 'e'], ['gw2', 'a'], ['gw2', 'b'], ['gw2', 'c'], ['gw2', 'd'], ['gw2', 'e'], ['a', 'b'], ['b', 'c'], ['a', 'd'], ['d', 'e'], ['c', 'e'], ['a', 'e'], ['b', 'e'], ['gw1', 'gw2']];
    foreach ($pairs as [$from, $to]) {
        $requests[] = ['from' => $rects[$from], 'to' => $rects[$to], 'obstacles' => gridObstacles($rects, $from, $to), 'ports' => null];
    }
    $requests[] = ['from' => $loose['spine'], 'to' => $rects['d'], 'obstacles' => gridObstacles($rects, 'd'), 'ports' => [[578, 82], [392, 444]]];

    $used = [];
    $expected = [];
    foreach ($requests as $r) {
        $expected[] = GridRouter::route($r['from'], $r['to'], $r['obstacles'], $ctx['grid'], $used, $r['ports']);
    }

    $dir = sys_get_temp_dir();
    $script = $dir . '/netconf-grid-router-' . getmypid() . '.js';
    $input = $dir . '/netconf-grid-router-' . getmypid() . '.json';
    file_put_contents($script, "'use strict';\nvar fs = require('fs');\n" . $m[1] . "\n"
        . "var data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));\n"
        . "var grid = GridRouter.lines(data.rects, data.bounds);\n"
        . "var used = {}, out = [];\n"
        . "data.requests.forEach(function (r) { out.push(GridRouter.route(r.from, r.to, r.obstacles, grid, used, r.ports)); });\n"
        . "console.log(JSON.stringify({ grid: grid, paths: out }));\n");
    file_put_contents($input, json_encode(['rects' => array_values(array_merge($rects, $loose)), 'bounds' => $ctx['bounds'], 'requests' => $requests]));

    $result = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($input) . ' 2>&1');
    @unlink($script);
    @unlink($input);
    $decoded = json_decode($result, true);

    expect($decoded)->toBeArray("node said: $result");
    // the grid lines first: the cheapest place to see a difference
    $phpGrid = GridRouter::lines(array_values(array_merge($rects, $loose)), $ctx['bounds']);
    expect($decoded['grid'])->toBe($phpGrid);
    // and then the paths, with their lanes already taken by the ones before
    $used = [];
    $again = [];
    foreach ($requests as $r) {
        $again[] = GridRouter::route($r['from'], $r['to'], $r['obstacles'], $phpGrid, $used, $r['ports']);
    }
    expect($decoded['paths'])->toBe($again);
});
