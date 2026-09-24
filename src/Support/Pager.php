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
        $pager = self::of(count($rows), $page, $perPage);

        return ['rows' => array_slice($rows, ($pager['page'] - 1) * $pager['per_page'], $pager['per_page'])] + $pager;
    }

    /**
     * The same figures for rows that are paged elsewhere — LIMIT/OFFSET in SQL, where the
     * caller knows the total but never holds the rows (the Checks tab, plan §10.5). The
     * partial renders from these; `offset()` is what the query needs.
     *
     * @return array{page: int, pages: int, total: int, per_page: int, from: int, to: int}
     */
    public static function of(int $total, int|string|null $page, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, (int) $page));
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'per_page' => $perPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $perPage),
        ];
    }

    /**
     * @param  array{page: int, per_page: int}  $pager
     */
    public static function offset(array $pager): int
    {
        return ($pager['page'] - 1) * $pager['per_page'];
    }
}
