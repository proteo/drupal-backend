<?php

namespace Proteo\DrupalBackend\Tests\Search;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Search\Suggestion;
use Proteo\DrupalBackend\Search\SuggestionFetcher;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class SuggestionFetcherTest extends DescriptorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.timeout' => 5, 'drupal.connect_timeout' => 2, 'drupal.retries' => 0, 'drupal.retry_delay_ms' => 0, 'app.url' => 'http://app.test']);
        url()->forceRootUrl('http://app.test');
    }

    /**
     * @param  array<string, mixed>  $override  Merged over the talk fixture.
     */
    private function fetcher(array $override = []): SuggestionFetcher
    {
        $descriptor = $this->descriptor(array_replace_recursive($this->fixture('conference'), $override));

        return new SuggestionFetcher(new DrupalManager($descriptor), $descriptor);
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents  Native collection documents by collection name.
     */
    private function fake(array $documents): void
    {
        Http::fake(fn (Request $request) => Http::response($documents[basename((string) parse_url($request->url(), PHP_URL_PATH))] ?? ['data' => []]));
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function terms(string $type, array $attributes): array
    {
        return ['data' => array_map(fn (array $a, int $i): array => ['type' => $type, 'id' => $type.$i, 'attributes' => $a], $attributes, array_keys($attributes))];
    }

    /**
     * @return array<string, mixed>
     */
    private function names(string $type, string $field, string $path, int $count): array
    {
        return $this->terms($type, array_map(fn (int $i): array => [$field => "Name $i", 'path' => "/$path/$i", 'talk_count' => 1], range(1, $count)));
    }

    public function test_it_suggests_each_type_with_its_url_and_subtitle(): void
    {
        $this->fake([
            'talk' => [
                'data' => [['type' => 'node--talk', 'id' => 'b1', 'attributes' => ['title' => 'Tom &amp; Jerry', 'path' => '/tom'], 'relationships' => ['speaker' => ['data' => [['type' => 'node--speaker', 'id' => 'a1']]]]]],
                'included' => [['type' => 'node--speaker', 'id' => 'a1', 'attributes' => ['title' => 'Frantz Fanon']]],
            ],
            'topic' => $this->terms('taxonomy_term--topic', [['name' => 'Africa Fanon', 'path' => '/topic/africa-fanon', 'talk_count' => 2]]),
        ]);

        $found = $this->fetcher()->suggest('afri');

        $this->assertEquals([
            new Suggestion('talk', 'Tom & Jerry', 'http://app.test/talk/tom', 'Frantz Fanon'),
            new Suggestion('topic', 'Africa Fanon', 'http://app.test/topic/africa-fanon'),
        ], $found);
    }

    public function test_each_type_is_queried_on_its_native_collection_by_its_own_title_field(): void
    {
        $this->fake([]);

        $this->fetcher()->suggest('africa');

        $expected = ['talk' => 'title', 'workshop' => 'title', 'topic' => 'name', 'track' => 'name'];

        foreach ($expected as $collection => $field) {
            Http::assertSent(function (Request $request) use ($collection, $field): bool {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return basename((string) parse_url($request->url(), PHP_URL_PATH)) === $collection
                    && $query['filter'][$field] === ['operator' => 'CONTAINS', 'value' => 'africa']
                    && $query['sort'] === $field
                    && $query['page']['limit'] === '10';
            });
        }
    }

    public function test_entries_that_fail_a_condition_are_dropped(): void
    {
        $this->fake(['track' => $this->terms('taxonomy_term--tracks', [
            ['name' => 'Empty', 'path' => '/track/empty', 'talk_count' => 0],
            ['name' => 'Full', 'path' => '/track/full', 'talk_count' => 1],
        ])]);

        $found = $this->fetcher()->suggest('ul');

        $this->assertSame(['Full'], array_map(fn (Suggestion $s): string => $s->title, $found));
    }

    public function test_condition_attributes_are_requested_with_the_suggestions(): void
    {
        $this->fake(['track' => $this->terms('taxonomy_term--tracks', [])]);

        $this->fetcher()->suggest('ul');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! str_contains($request->url(), 'track') || str_contains($query['fields']['taxonomy_term--tracks'] ?? '', 'talk_count');
        });
    }

    public function test_quotas_hold_each_type_and_spare_places_go_to_the_rest_in_tab_order(): void
    {
        $this->fake([
            'topic' => $this->names('taxonomy_term--topic', 'name', 'topic', 6),
            'track' => $this->names('taxonomy_term--tracks', 'name', 'track', 6),
        ]);

        $found = $this->fetcher()->suggest('name');

        $this->assertCount(10, $found);
        $this->assertSame(['topic' => 6, 'track' => 4], array_count_values(array_map(fn (Suggestion $s): string => $s->type, $found)));
        $this->assertSame(['topic', 'track', 'track'], array_map(fn (Suggestion $s): string => $s->type, array_slice($found, 0, 3)));
    }

    public function test_places_left_by_declared_quotas_are_split_evenly_between_the_other_tabs(): void
    {
        $this->fake([
            'topic' => $this->names('taxonomy_term--topic', 'name', 'topic', 9),
            'track' => $this->names('taxonomy_term--tracks', 'name', 'track', 9),
        ]);

        $found = $this->fetcher(['search' => ['suggestions' => ['limit' => 7, 'quotas' => ['talk' => 3, 'speaker' => 0]]]])->suggest('name');

        // 7 places, 3 declared: the two open tabs get 2 each, then the unused places go to the rest in tab order.
        $this->assertCount(7, $found);
        $this->assertSame(['topic' => 5, 'track' => 2], array_count_values(array_map(fn (Suggestion $s): string => $s->type, $found)));
    }

    public function test_limit_and_quotas_are_configurable(): void
    {
        $this->fake(['track' => $this->names('taxonomy_term--tracks', 'name', 'track', 6)]);

        $found = $this->fetcher(['search' => ['suggestions' => ['limit' => 4, 'quotas' => ['track' => 1]]]])->suggest('name');

        $this->assertCount(4, $found);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['page']['limit'] === '4';
        });
    }

    public function test_the_term_is_trimmed_capped_and_checked_against_the_configured_length(): void
    {
        $this->fake([]);
        $fetcher = $this->fetcher(['search' => ['term' => ['min' => 3, 'max' => 5]]]);

        $this->assertSame([], $fetcher->suggest(' ab '));
        Http::assertNothingSent();

        $fetcher->suggest('  abcdefgh');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['filter']['title'] ?? $query['filter']['name'])['value'] === 'abcde';
        });
    }

    public function test_an_upstream_failure_gives_no_suggestions(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->assertSame([], $this->fetcher()->suggest('wash'));
    }

    public function test_nothing_is_suggested_when_search_is_off(): void
    {
        Http::fake();

        $this->assertSame([], $this->fetcher(['search' => ['enabled' => false]])->suggest('wash'));
        Http::assertNothingSent();
    }
}
