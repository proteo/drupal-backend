<?php

namespace Proteo\DrupalBackend\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Proteo\DrupalBackend\Content\ContentFetcher;
use Proteo\DrupalBackend\Descriptor\FilterType;
use Proteo\DrupalBackend\Exceptions\DrupalException;
use Proteo\DrupalBackend\Livewire\Concerns\PaginatesListing;
use Proteo\DrupalBackend\Livewire\Forms\SearchForm;
use Proteo\DrupalBackend\Search\SanitisedTerm;
use Proteo\DrupalBackend\Search\SearchFetcher;
use Proteo\DrupalBackend\Search\SearchFilters;
use Proteo\DrupalBackend\Search\SearchFilterSet;
use Proteo\DrupalBackend\Search\SearchResults;
use Proteo\DrupalBackend\Search\SearchSorts;
use Proteo\DrupalBackend\Search\SearchTabs;
use Proteo\DrupalBackend\Search\TermSanitiser;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * The advanced search page: term, tabs, results and pagination, all kept in the URL.
 */
class Search extends Component
{
    use PaginatesListing;

    public SearchForm $form;

    /**
     * Raw filter input by URL parameter. The parameters come from the descriptor,
     * so they are aliased in queryString() instead of declared as properties.
     *
     * @var array<string, mixed>
     */
    public array $filters = [];

    #[Url(as: 'tab')]
    public string $tab = '';

    /**
     * Set by a submit for the rest of that request only.
     */
    private bool $switchToFirstNonEmptyTab = false;

    /**
     * @return array<string, array{as: string, history: bool}>
     */
    protected function queryString(): array
    {
        $query = [];

        foreach (app(SearchFilterSet::class)->parameters() as $parameter) {
            // One action can change several filters; they replace the history entry instead of stacking several.
            $query["filters.$parameter"] = ['as' => $parameter, 'history' => false];
        }

        return $query;
    }

    public function mount(): void
    {
        // Every parameter must exist as a property path for its URL alias to be written.
        $this->filters = array_replace(app(SearchFilterSet::class)->defaults(), $this->filters);

        // The first tab is the default one, so it stays out of the URL.
        $this->tab = app(SearchTabs::class)->resolve($this->tab);
    }

    public function search(): void
    {
        $this->form->validate();
        $this->page = 1;
        $this->tab = app(SearchTabs::class)->default();
        $this->switchToFirstNonEmptyTab = true;
    }

    public function updatedFormSort(): void
    {
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->filters = app(SearchFilterSet::class)->defaults();
        $this->page = 1;
    }

    /**
     * The term stays; filters and an order the new tab does not declare are dropped.
     */
    public function selectTab(string $tab): void
    {
        $this->tab = app(SearchTabs::class)->resolve($tab);
        $this->page = 1;

        $filterSet = app(SearchFilterSet::class);
        $declared = $filterSet->parametersOf($this->tab);

        foreach ($filterSet->defaults() as $parameter => $default) {
            if (! in_array($parameter, $declared, true)) {
                $this->filters[$parameter] = $default;
            }
        }

        $hasTerm = app(TermSanitiser::class)->sanitise($this->form->displayTerm())->isUsable();

        if (! array_key_exists($this->form->sort, app(SearchSorts::class)->options($this->tab, $hasTerm))) {
            $this->form->sort = SearchSorts::RELEVANCE;
        }
    }

    /**
     * @throws \DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function render(
        SearchFetcher $fetcher,
        TermSanitiser $sanitiser,
        ContentFetcher $content,
        SearchTabs $tabs,
        SearchSorts $sorts,
        SearchFilterSet $filterSet,
    ): View {
        $filters = $filterSet->validate($this->filters);
        $term = $this->form->displayTerm();
        $sanitised = $sanitiser->sanitise($term);

        $results = null;
        $pages = [];
        $failed = false;

        // A search needs a usable term or at least one filter.
        $searched = $sanitised->isUsable() || $filters->isActive();

        if ($searched) {
            try {
                $results = $this->fetch($fetcher, $tabs, $sorts, $sanitised, $filters);
                $pages = $this->pageLinks($results->listing->hasMore, $this->linkQuery($filters, $sanitised, $tabs, $sorts));
            } catch (DrupalException $e) {
                // Visitors get a generic message; the upstream detail goes to the logs.
                report($e);
                $failed = true;
            }
        }

        $activeTab = $results?->tab ?? $tabs->resolve($this->tab);

        return view()->first(['livewire.search', 'drupal::livewire.search'], [
            'term' => $term,
            'highlight' => $sanitised->terms,
            'searched' => $searched,
            'hasTerm' => $sanitised->isUsable(),
            // A term that arrived through the link is never validated, so the message is derived here.
            'tooShort' => $term !== '' && ! $sanitised->isUsable(),
            'results' => $results,
            'activeTab' => $activeTab,
            'tabs' => $tabs,
            'visibleTabs' => $results?->visibleTabs() ?? [],
            'active' => $filters,
            'filterSet' => $filterSet,
            'sorts' => $sorts->options($activeTab, $sanitised->isUsable()),
            'sortOrder' => $this->form->sortOrder($activeTab, $sanitised->isUsable()),
            'unknownSort' => $this->form->hasUnknownSort(),
            'linkQuery' => $this->linkQuery($filters, $sanitised, $tabs, $sorts),
            'options' => $this->filterOptions($filterSet, $content),
            'pages' => $pages,
            'failed' => $failed,
            'searchUrl' => route('search'),
        ]);
    }

    /**
     * Choices of each `equals` filter by its key; null when they could not be loaded.
     *
     * @return array<string, array<string, string>|null>
     */
    private function filterOptions(SearchFilterSet $filterSet, ContentFetcher $content): array
    {
        $options = [];

        foreach ($filterSet->filters() as $filter) {
            if ($filter->type !== FilterType::Equals) {
                continue;
            }

            try {
                $options[$filter->key] = $content->options($filterSet->optionsOf($filter));
            } catch (DrupalException $e) {
                report($e);
                $options[$filter->key] = null;
            }
        }

        return $options;
    }

    /**
     * What the pagination and tab links must carry besides their own parameter.
     *
     * @return array<string, string|int>
     */
    private function linkQuery(SearchFilters $filters, SanitisedTerm $term, SearchTabs $tabs, SearchSorts $sorts): array
    {
        $sort = $this->form->sortOrder($tabs->resolve($this->tab), $term->isUsable());

        return array_filter([
            'q' => $this->form->term,
            'tab' => $this->tab,
            ...array_map(fn (string|int|bool $value): string|int => $value === true ? 1 : $value, $filters->values),
            'sort' => $sort === SearchSorts::RELEVANCE ? null : $sort,
        ]);
    }

    /**
     * @throws \DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    private function fetch(SearchFetcher $fetcher, SearchTabs $tabs, SearchSorts $sorts, SanitisedTerm $term, SearchFilters $filters): SearchResults
    {
        $tab = $tabs->resolve($this->tab);
        $results = $fetcher->search($term, $tab, $this->currentPage(), $filters, $this->form->sortOrder($tab, $term->isUsable()));

        // Only a submit without filters may move the visitor; clicks and pagination keep their tab.
        if ($this->switchToFirstNonEmptyTab && ! $filters->isActive() && $results->count($tabs->default()) === 0) {
            $first = $results->firstNonEmptyTab();

            if ($first !== null) {
                $this->tab = $first;
                $results = $fetcher->search($term, $first, 1, $filters, $this->form->sortOrder($first, $term->isUsable()));
            }
        }

        return $results;
    }
}
