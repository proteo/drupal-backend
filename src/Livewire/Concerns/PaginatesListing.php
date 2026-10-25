<?php

namespace Proteo\DrupalBackend\Livewire\Concerns;

use Livewire\Attributes\Url;
use Livewire\Livewire;
use Proteo\DrupalBackend\Support\PaginationLinks;

/**
 * Binds a listing component to the `page` query parameter and derives its
 * previous/next links from the page's own path (Livewire's original path on
 * update requests).
 */
trait PaginatesListing
{
    #[Url(as: 'page')]
    public int $page = 1;

    public function previousPage(): void
    {
        $this->page = max(1, $this->currentPage() - 1);
    }

    public function nextPage(): void
    {
        $this->page = $this->currentPage() + 1;
    }

    /**
     * Zero, negative and nonsense values are treated as the first page.
     */
    protected function currentPage(): int
    {
        return max(1, $this->page);
    }

    /**
     * @param  array<string, mixed>  $query  Extra parameters to keep in the links; the request's own query is not
     *                                       available on Livewire update requests.
     * @return array{prev?: string, next?: string}
     */
    protected function pageLinks(bool $hasMore, array $query = []): array
    {
        return PaginationLinks::build(
            $this->currentPage(),
            $hasMore,
            Livewire::originalPath(),
            $query + request()->query(),
        );
    }
}
