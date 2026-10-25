<?php

namespace Proteo\DrupalBackend\Search;

use InvalidArgumentException as InvalidArgument;
use LogicException;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\SearchConfig;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Searches the descriptor's searchable types, one tab each. The
 * {@see SearchBackend} plans each tab; this sends every plan's requests as
 * one concurrent batch and hands each plan its responses.
 */
class SearchFetcher
{
    private readonly SearchConfig $config;

    private readonly SearchFilterSet $filterSet;

    public function __construct(
        private readonly DrupalManager $drupal,
        private readonly Descriptor $descriptor,
        private readonly SearchBackend $backend,
    ) {
        $this->config = $descriptor->search;
        $this->filterSet = new SearchFilterSet($descriptor);
    }

    /**
     * One page of the active tab plus a count for each other tab, fetched
     * concurrently. If any request fails the whole search fails.
     *
     * @param  string  $tab  Content type key; an unknown or unsearched one means the first tab.
     * @param  string  $sort  `relevance`, `title` or a name declared in the active type's `sorts`.
     *
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function search(SanitisedTerm $term, string $tab, int $page, SearchFilters $filters = new SearchFilters, string $sort = 'relevance'): SearchResults
    {
        $drupal = $this->drupal->source();
        $offset = $this->config->perPage * (max(1, $page) - 1);

        // A filter belongs to one type, so only the tabs of the types whose filters are in use can answer it.
        $tabs = $filters->isActive() ? $this->filterSet->owners($filters) : $this->config->tabs;
        $active = in_array($tab, $tabs, true) ? $tab : ($tabs[0] ?? throw new LogicException('Search needs a searchable content type.'));

        $plans = [];
        $requests = [];

        foreach ($tabs as $key) {
            $plans[$key] = ['plan' => $plan = $this->backend->plan(new TabSearch(
                $this->descriptor->contentType($key), $term, $filters, $sort, $offset, $key === $active,
            )), 'start' => count($requests)];
            array_push($requests, ...$plan->requests);
        }

        $responses = $drupal->search($requests);

        $counts = [];
        $exact = [];
        $listing = null;

        foreach ($plans as $key => ['plan' => $plan, 'start' => $start]) {
            $result = $plan->resolve(array_slice($responses, $start, count($plan->requests)), $drupal);
            $counts[$key] = $result->count;
            $exact[$key] = $result->exact;
            $listing = $key === $active ? $result->listing : $listing;
        }

        return new SearchResults($active, $counts, $listing ?? throw new InvalidArgument("Tab [$active] was not searched."), $exact);
    }
}
