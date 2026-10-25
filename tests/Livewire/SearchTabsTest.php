<?php

namespace Proteo\DrupalBackend\Tests\Livewire;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Proteo\DrupalBackend\Livewire\Search;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\TestCase;

class SearchTabsTest extends TestCase
{
    use FakesDrupal;

    private const string LEVEL = 'e34b1bd7-3e4a-4fd4-95e3-1eb97741f0ce';

    /**
     * The conference model without its `track` tab: two searchable Primaries, each with a filter of its own, and a searchable Facet.
     *
     * @return array<string, mixed>
     */
    private function descriptorConfig(): array
    {
        $config = require __DIR__.'/../Fixtures/conference.php';
        unset($config['search']['tabs']);
        $config['content_types']['track']['searchable'] = false;

        return $config;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('drupal-backend', $this->descriptorConfig());
        $app['config']->set('drupal.cache.enabled', false);
    }

    /**
     * Answers each native collection with the given titles.
     *
     * @param  array<string, list<string>>  $titles  Titles by collection.
     */
    private function fakeCollections(array $titles): void
    {
        $entities = [];

        Http::fake(function (Request $request) use ($titles, &$entities) {
            $collection = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $type = $collection === 'topic' ? 'topic' : $collection;
            $field = $type === 'topic' ? 'name' : 'title';
            // The same ids on every request, as the related-path requests return the same items.
            $items = $entities[$collection] ??= array_map(fn (string $title): array => $this->fakeEntity($type, [$field => $title] + ($type === 'topic' ? ['talk_count' => 1] : [])), $titles[$collection] ?? []);

            return Http::response($this->fakeCollection($type, $items));
        });
    }

    public function test_every_searchable_type_gets_a_tab_in_descriptor_order(): void
    {
        $this->fakeCollections(['talk' => ['Dune'], 'workshop' => ['Dune Lab']]);

        $component = Livewire::test(Search::class)->set('form.term', 'dune')->call('search');

        $results = $component->viewData('results');
        $this->assertSame(['talk', 'workshop', 'topic'], $results->tabs());
        $this->assertSame(1, $results->count('talk'));
        $this->assertSame(1, $results->count('workshop'));
    }

    public function test_a_tab_counted_only_as_a_floor_renders_with_a_plus(): void
    {
        // Only the talk collection reports a next page, so only its count is a floor.
        Http::fake(function (Request $request) {
            $isTalk = basename((string) parse_url($request->url(), PHP_URL_PATH)) === 'talk';

            return Http::response($this->fakeCollection($isTalk ? 'talk' : 'workshop', 2, hasMore: $isTalk));
        });

        Livewire::test(Search::class)
            ->set('form.term', 'dune')
            ->call('search')
            ->assertSeeHtml('Talks (8+)')
            ->assertSeeHtml('Workshops (2)');
    }

    public function test_a_filter_shows_only_the_tab_of_the_type_that_declares_it(): void
    {
        $this->fakeCollections(['workshop' => ['Dune Lab']]);

        $component = Livewire::test(Search::class)->set('form.term', 'dune')->set('filters.level', self::LEVEL)->call('search');

        $this->assertSame(['workshop'], $component->viewData('results')->tabs());
        $this->assertSame('workshop', $component->viewData('activeTab'));
    }

    public function test_switching_tabs_keeps_the_term_and_drops_filters_and_sorts_the_tab_does_not_declare(): void
    {
        $this->fakeCollections(['talk' => ['Dune'], 'workshop' => ['Dune Lab']]);

        $component = Livewire::test(Search::class)
            ->set('form.term', 'dune')
            ->set('form.sort', 'soonest')
            ->set('filters.level', self::LEVEL)
            ->call('selectTab', 'workshop')
            ->assertSet('filters.level', self::LEVEL)
            ->assertSet('form.sort', 'soonest')
            ->call('selectTab', 'talk');

        $component->assertSet('form.term', 'dune')->assertSet('filters.level', '')->assertSet('form.sort', 'relevance')->assertSet('page', 1);
    }

    public function test_only_a_submit_moves_to_the_first_non_empty_tab(): void
    {
        $this->fakeCollections(['workshop' => ['Dune Lab']]);

        $component = Livewire::test(Search::class)->set('form.term', 'dune')->call('search');
        $this->assertSame('workshop', $component->get('tab'));

        // A click on an empty tab stays where it is.
        $component->call('selectTab', 'talk');
        $this->assertSame('talk', $component->get('tab'));
        $this->assertSame('talk', $component->viewData('activeTab'));
    }
}
