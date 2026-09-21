<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * Server-side paging for the fabric tables: a VNI list grows with every member, and rendering
 * it as one table costs hundreds of kB per page view. Pure array slicing so the callers keep
 * their filter and sort; the query string of the page links is built by the pager partial.
 */
final class Pager
{
    public const PER_PAGE = 100;

    /**
     * @template T
     *
     * @param  list<T>  $rows  already filtered and sorted
     * @return array{rows: list<T>, page: int, pages: int, total: int, per_page: int, from: int, to: int}
     */
    public static function slice(array $rows, int|string|null $page, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, (int) $page));
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'per_page' => $perPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $perPage),
        ];
    }
}
