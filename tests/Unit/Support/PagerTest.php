<?php

use SafferIt\LibrenmsNetconf\Support\Pager;

it('slices a list into pages and clamps the page number', function () {
    $rows = range(1, 250);

    expect(Pager::slice($rows, 1, 100))->toMatchArray(['page' => 1, 'pages' => 3, 'total' => 250, 'from' => 1, 'to' => 100])
        ->and(Pager::slice($rows, 1, 100)['rows'][0])->toBe(1)
        ->and(Pager::slice($rows, 3, 100))->toMatchArray(['page' => 3, 'from' => 201, 'to' => 250])
        ->and(Pager::slice($rows, 3, 100)['rows'])->toHaveCount(50)
        ->and(Pager::slice($rows, 99, 100)['page'])->toBe(3)
        ->and(Pager::slice($rows, 0, 100)['page'])->toBe(1)
        ->and(Pager::slice($rows, null, 100)['page'])->toBe(1)
        ->and(Pager::slice($rows, 'nope', 100)['page'])->toBe(1);
});

it('is one page for an empty or short list', function () {
    expect(Pager::slice([], 2))->toMatchArray(['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'from' => 0, 'to' => 0])
        ->and(Pager::slice([1, 2, 3], 1))->toMatchArray(['pages' => 1, 'from' => 1, 'to' => 3]);
});
