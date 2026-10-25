<?php

namespace Proteo\DrupalBackend\Tests\Search;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Search\NativeQueries;
use Proteo\DrupalBackend\Search\NativeSearchBackend;
use Proteo\DrupalBackend\Search\SanitisedTerm;
use Proteo\DrupalBackend\Search\SearchFetcher;
use Proteo\DrupalBackend\Search\SearchFilters;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class SearchFetcherTest extends DescriptorTestCase
{
    use FakesDrupal;

    private ?Descriptor $descriptor = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.cache.enabled' => false, 'drupal.timeout' => 5, 'drupal.connect_timeout' => 2, 'drupal.retries' => 0, 'drupal.retry_delay_ms' => 0]);
    }

    protected function drupalDescriptor(): Descriptor
    {
        return $this->descriptor ?? throw new \LogicException('Call fetcher() first.');
    }

    /**
     * @param  ?array<string, mixed>  $config
     */
    private function fetcher(?array $config = null): SearchFetcher
    {
        $config ??= $this->fixture('conference');
        $this->descriptor = $this->descriptor($config);

        return new SearchFetcher(
            new DrupalManager($this->descriptor),
            $this->descriptor,
            new NativeSearchBackend(
                $this->descriptor,
                new ContentMapper($this->descriptor, new EntityFactory($this->descriptor), 'https://app.test'),
                new NativeQueries($this->descriptor->search),
            ),
        );
    }

    private function term(string ...$words): SanitisedTerm
    {
        return new SanitisedTerm(implode(' ', $words), array_values($words));
    }

    /**
     * Answers each request by the collection it names.
     *
     * @param  array<string, array<string, mixed>>  $documents  Keyed by collection.
     */
    private function fakeByResource(array $documents): void
    {
        $empty = ['data' => [], 'meta' => ['count' => 0], 'links' => []];

        Http::fake(function (Request $request) use ($documents, $empty) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $key = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response($documents[$key] ?? $empty);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queries(): array
    {
        return Http::recorded()->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query + ['_path' => basename((string) parse_url($pair[0]->url(), PHP_URL_PATH))];
        })->all();
    }

    public function test_native_contains_matches_partial_words_on_the_native_collections(): void
    {
        $fetcher = $this->fetcher();
        $talk = $this->fakeEntity('talk', ['title' => 'Classics Revisited']);
        $this->fakeByResource(['talk' => $this->fakeCollection('talk', [$talk])]);

        $results = $fetcher->search($this->term('class'), 'talk', 1);

        $this->assertSame(['talk', 'workshop', 'topic', 'track'], $results->tabs());
        $this->assertSame(1, $results->count('talk'));
        $this->assertSame('Classics Revisited', $results->listing->items->first()->title);

        $paths = array_column($this->queries(), '_path');
        sort($paths);
        $this->assertSame(['talk', 'talk', 'talk', 'talk', 'topic', 'track', 'workshop'], $paths);
        $this->assertNotContains('default', $paths);
    }

    public function test_native_contains_sends_one_request_per_related_path_with_its_own_weight(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1);

        $related = array_values(array_filter(
            $this->queries(),
            fn (array $query): bool => $query['_path'] === 'talk' && isset($query['filter']['word0']['condition']),
        ));

        $this->assertSame(
            ['speaker.title', 'topic.name', 'tracks.name'],
            array_map(fn (array $query): string => $query['filter']['word0']['condition']['path'], $related),
        );
    }

    public function test_native_contains_scores_by_term_field_weights(): void
    {
        $fetcher = $this->fetcher();
        $description = $this->fakeEntity('talk', ['title' => 'Unrelated', 'date' => '2025-01-01T00:00:00+00:00'], [], ['description' => ['value' => 'Mentions apartheid.']]);
        $title = $this->fakeEntity('talk', ['title' => 'Apartheid Did Not Die', 'date' => '2020-01-01T00:00:00+00:00']);
        $this->fakeByResource(['talk' => $this->fakeCollection('talk', [$description, $title])]);

        $titles = $fetcher->search($this->term('apartheid'), 'talk', 1)->listing->items->map(fn ($talk) => $talk->title)->all();

        $this->assertSame(['Apartheid Did Not Die', 'Unrelated'], $titles);
    }

    public function test_native_contains_drops_entries_that_fail_a_condition(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource(['topic' => $this->fakeCollection('topic', [
            $this->fakeEntity('topic', ['name' => 'Has talks', 'talk_count' => 3]),
            $this->fakeEntity('topic', ['name' => 'No talks', 'talk_count' => 0]),
        ])]);

        $results = $fetcher->search($this->term('has'), 'topic', 1);

        $this->assertSame(1, $results->count('topic'));
        $this->assertSame('Has talks', $results->listing->items->first()->name);
    }

    public function test_active_filters_search_the_primary_only(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource([]);

        $results = $fetcher->search($this->term('fanon'), 'speaker', 1, new SearchFilters(['track' => 't-1']));

        $this->assertSame('talk', $results->tab);
        $this->assertSame(['talk'], $results->tabs());
        $this->assertSame('t-1', $this->queries()[0]['filter']['tracks.id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function twoPrimaries(): array
    {
        $config = $this->fixture('conference');
        $config['content_types']['journal'] = [
            'entity_type' => 'node',
            'bundle' => 'journal',
            'fields' => ['title' => ['source' => 'title', 'required' => true]],
            'filters' => ['peer' => ['type' => 'boolean', 'fields' => ['peer' => ['native' => 'peer_reviewed']]]],
        ];
        $config['search']['tabs'] = ['talk', 'journal', 'topic'];

        return $config;
    }

    public function test_a_filter_hides_the_tabs_of_the_other_types_even_when_one_is_requested(): void
    {
        $fetcher = $this->fetcher($this->twoPrimaries());
        $this->fakeByResource([]);

        $results = $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['peer' => true]));

        $this->assertSame(['journal'], $results->tabs());
        $this->assertSame('journal', $results->tab);
    }

    public function test_every_tab_is_searched_with_the_term_when_no_filter_is_active(): void
    {
        $fetcher = $this->fetcher($this->twoPrimaries());
        $this->fakeByResource(['journal' => $this->fakeCollection('journal', [$this->fakeEntity('journal', ['title' => 'Fanon Review'])])]);

        $results = $fetcher->search($this->term('fanon'), 'journal', 1);

        $this->assertSame(['talk' => 0, 'journal' => 1, 'topic' => 0], $results->counts);
        $this->assertSame('Fanon Review', $results->listing->items->first()->title);
    }

    public function test_a_facet_is_matched_on_its_default_term_field(): void
    {
        $fetcher = $this->fetcher($this->twoPrimaries());
        $this->fakeByResource(['topic' => $this->fakeCollection('topic', [$this->fakeEntity('topic', ['name' => 'Fanon', 'talk_count' => 2])])]);

        $results = $fetcher->search($this->term('fanon'), 'topic', 1);

        $this->assertSame(1, $results->count('topic'));
        $topic = array_values(array_filter($this->queries(), fn (array $q): bool => $q['_path'] === 'topic'))[0];
        $this->assertSame('name', $topic['filter']['word0-name']['condition']['path']);
    }

    public function test_a_boolean_filter_with_native_paths_matches_in_an_or_group(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['fields'] = [
            'slides' => ['native' => 'links_sa'],
            'recording' => ['native' => 'recording_native'],
        ];
        $fetcher = $this->fetcher($config);
        $this->fakeByResource(['talk' => $this->fakeCollection('talk', [$this->fakeEntity('talk', ['title' => 'Sale'])])]);

        $fetcher->search($this->term('sale'), 'talk', 1, new SearchFilters(['slides' => true, 'recording' => true]));

        $queries = $this->queries();
        $this->assertNotContains('default', array_column($queries, '_path'));
        $this->assertSame('OR', $queries[0]['filter']['materials']['group']['conjunction']);
        $this->assertSame('recording_native', $queries[0]['filter']['recording']['condition']['path']);
        $this->assertSame('1', $queries[0]['filter']['slides']['condition']['value']);
    }

    public function test_filters_and_the_sort_become_native_paths(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['topic' => 'c-1', 'year' => 2024, 'slides' => true]), 'oldest');

        $query = $this->queries()[0];
        $this->assertSame('c-1', $query['filter']['topic.id']);
        $this->assertSame('1', $query['filter']['has_slides']);
        $this->assertSame('>=', $query['filter']['year_from']['condition']['operator']);
        $this->assertSame('created', $query['filter']['year_from']['condition']['path']);
        $this->assertSame('created', $query['sort']);
        $this->assertSame('cover.field_media_image,speaker,topic,slides_resources,recording_resources', $query['include']);
    }

    public function test_a_sort_the_type_does_not_declare_is_not_sent(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'workshop', 1, sort: 'newest');

        $speaker = array_values(array_filter($this->queries(), fn (array $q): bool => $q['_path'] === 'workshop'))[0];
        $this->assertArrayNotHasKey('sort', $speaker);
    }

    public function test_match_all_requires_every_checked_field(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['match'] = 'all';
        $fetcher = $this->fetcher($config);
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['slides' => true, 'recording' => true]));

        $query = $this->queries()[0];
        $this->assertSame('1', $query['filter']['has_slides']);
        $this->assertSame('1', $query['filter']['has_recording']);
        $this->assertArrayNotHasKey('materials', $query['filter']);
    }

    public function test_a_present_boolean_filters_on_a_value_instead_of_one(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['fields'] = [
            'slides' => ['native' => 'slides_resources', 'present' => true],
            'recording' => ['native' => 'has_recording'],
        ];
        $fetcher = $this->fetcher($config);
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['slides' => true]));
        $alone = $this->queries()[0]['filter']['slides']['condition'];

        $this->assertSame(['path' => 'slides_resources', 'operator' => 'IS NOT NULL'], $alone);
    }

    public function test_a_present_boolean_joins_an_or_group_with_its_equality_siblings(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['fields'] = [
            'slides' => ['native' => 'slides_resources', 'present' => true],
            'recording' => ['native' => 'has_recording'],
        ];
        $fetcher = $this->fetcher($config);
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['slides' => true, 'recording' => true]));

        $filter = $this->queries()[0]['filter'];
        $this->assertSame('OR', $filter['materials']['group']['conjunction']);
        $this->assertSame('IS NOT NULL', $filter['slides']['condition']['operator']);
        $this->assertSame('materials', $filter['slides']['condition']['memberOf']);
        $this->assertSame('1', $filter['recording']['condition']['value']);
    }

    public function test_an_unchecked_boolean_adds_no_filter(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['slides' => false]));

        $this->assertArrayNotHasKey('has_slides', $this->queries()[0]['filter'] ?? []);
    }

    public function test_a_boolean_without_a_native_path_adds_no_native_filter(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['fields']['recording'] = [];
        $fetcher = $this->fetcher($config);
        $this->fakeByResource([]);

        $fetcher->search($this->term('fanon'), 'talk', 1, new SearchFilters(['slides' => true, 'recording' => true]));

        $this->assertArrayNotHasKey('has_slides', $this->queries()[0]['filter'] ?? []);
        $this->assertArrayNotHasKey('materials', $this->queries()[0]['filter'] ?? []);
    }

    private function noTerm(): SanitisedTerm
    {
        return new SanitisedTerm('', []);
    }

    public function test_a_filter_only_search_pages_the_native_collection_by_offset(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource(['talk' => $this->fakeCollection('talk', 18, hasMore: true)]);

        $results = $fetcher->search($this->noTerm(), 'talk', 2, new SearchFilters(['track' => 't-1']), 'relevance');

        $this->assertSame(['talk'], array_column($this->queries(), '_path'));
        $query = $this->queries()[0];
        $this->assertSame('18', $query['page']['limit']);
        $this->assertSame('18', $query['page']['offset']);
        $this->assertSame('t-1', $query['filter']['tracks.id']);
        $this->assertSame('-created', $query['sort']);
        $this->assertSame(37, $results->count('talk'));
        $this->assertSame('37+', $results->countLabel('talk'));
        $this->assertTrue($results->listing->hasMore);
        $this->assertNull($results->listing->total);
    }

    public function test_the_last_filter_only_page_has_an_exact_count(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource(['talk' => $this->fakeCollection('talk', 4)]);

        $results = $fetcher->search($this->noTerm(), 'talk', 3, new SearchFilters(['track' => 't-1']));

        $this->assertSame('40', $results->countLabel('talk'));
        $this->assertFalse($results->listing->hasMore);
    }

    public function test_a_filter_only_search_sorts_by_the_chosen_order(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource([]);

        $fetcher->search($this->noTerm(), 'talk', 1, new SearchFilters(['track' => 't-1']), 'title');

        $this->assertSame('title', $this->queries()[0]['sort']);
    }

    public function test_a_term_tab_is_inexact_when_a_request_reports_more(): void
    {
        $fetcher = $this->fetcher();
        $this->fakeByResource(['talk' => $this->fakeCollection('talk', 3, hasMore: true), 'workshop' => $this->fakeCollection('workshop', 2)]);

        $results = $fetcher->search($this->term('class'), 'talk', 1);

        $this->assertSame('3+', $results->countLabel('talk'));
        $this->assertSame('2', $results->countLabel('workshop'));
    }
}
