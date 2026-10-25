<?php

namespace Proteo\DrupalBackend\Support;

/**
 * Builds the previous/next link array that <x-pagination> consumes.
 */
final class PaginationLinks
{
    /**
     * @param  array<string, mixed>  $query  Query of the listing's own URL; its `page` is replaced.
     * @return array{prev?: string, next?: string}
     */
    public static function build(int $page, bool $hasMore, string $path, array $query = []): array
    {
        $page = max(1, $page);
        unset($query['page']);

        $links = [];

        if ($page > 1) {
            // Page 1 is the clean URL; "?page=1" would duplicate it.
            $links['prev'] = self::url($path, $page > 2 ? $query + ['page' => $page - 1] : $query);
        }

        if ($hasMore) {
            $links['next'] = self::url($path, $query + ['page' => $page + 1]);
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function url(string $path, array $query): string
    {
        return url()->to('/'.ltrim($path, '/')).($query === [] ? '' : '?'.http_build_query($query));
    }
}
