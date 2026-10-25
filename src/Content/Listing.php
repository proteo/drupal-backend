<?php

namespace Proteo\DrupalBackend\Content;

use Illuminate\Support\Collection;

/**
 * One page of a collection: the mapped items plus whether Drupal reported
 * a following page.
 *
 * @template TItem of object
 */
final readonly class Listing
{
    /**
     * @param  Collection<int, TItem>  $items
     * @param  ?int  $total  Matches across all pages, when the backend reports it.
     */
    public function __construct(
        public Collection $items,
        public bool $hasMore,
        public ?int $total = null,
    ) {}
}
