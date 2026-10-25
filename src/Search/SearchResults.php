<?php

namespace Proteo\DrupalBackend\Search;

use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\Listing;

/**
 * The outcome of one search: a page of the active tab and every tab's count.
 */
final readonly class SearchResults
{
    /**
     * @param  string  $tab  Content type key of the active tab.
     * @param  array<string, int>  $counts  Keyed by content type key, in display order.
     * @param  Listing<Entity>  $listing  The active tab's page.
     * @param  array<string, bool>  $exact  Keyed by content type key; a tab not listed is exact.
     */
    public function __construct(
        public string $tab,
        public array $counts,
        public Listing $listing,
        public array $exact = [],
    ) {}

    public function count(string $tab): int
    {
        return $this->counts[$tab] ?? 0;
    }

    /**
     * False when the count is a floor (matches cut at a request cap).
     */
    public function isExact(string $tab): bool
    {
        return $this->exact[$tab] ?? true;
    }

    /**
     * The count as shown: "12", or "50+" when it is only a floor.
     */
    public function countLabel(string $tab): string
    {
        return $this->count($tab).($this->isExact($tab) ? '' : '+');
    }

    /**
     * Tabs that were searched, in display order.
     *
     * @return list<string>
     */
    public function tabs(): array
    {
        return array_keys($this->counts);
    }

    /**
     * Tabs worth showing: those with matches, plus the active one so the
     * selected panel never lacks its tab (e.g. a stale tab in the URL).
     * Nothing is shown when the whole search came back empty.
     *
     * @return list<string>
     */
    public function visibleTabs(): array
    {
        if ($this->total() === 0) {
            return [];
        }

        return array_values(array_filter(
            $this->tabs(),
            fn (string $tab): bool => $tab === $this->tab || $this->count($tab) > 0,
        ));
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * The first tab, in display order, that has results.
     */
    public function firstNonEmptyTab(): ?string
    {
        return array_find($this->tabs(), fn (string $tab): bool => $this->count($tab) > 0);
    }
}
