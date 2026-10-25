<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Content\ContentFetcher;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class ContentFetcherTest extends DescriptorTestCase
{
    private const string UUID = 'e34b1bd7-3e4a-4fd4-95e3-1eb97741f0ce';

    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.cache.enabled' => false, 'drupal.timeout' => 5, 'drupal.connect_timeout' => 2, 'drupal.retries' => 0, 'drupal.retry_delay_ms' => 0]);
    }

    /**
     * @param  ?array<string, mixed>  $config
     */
    private function fetcher(?array $config = null): ContentFetcher
    {
        $descriptor = $this->descriptor($config ?? $this->fixture('conference'));

        return new ContentFetcher(
            new DrupalManager($descriptor),
            $descriptor,
            new ContentMapper($descriptor, new EntityFactory($descriptor), 'https://app.test'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(string $type, string $id, array $attributes): array
    {
        return ['type' => $type, 'id' => $id, 'attributes' => $attributes + ['path' => '/'.$id]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queries(): array
    {
        return Http::recorded()->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query + ['_path' => parse_url($pair[0]->url(), PHP_URL_PATH)];
        })->all();
    }

    public function test_item_resolves_the_alias_under_its_prefix(): void
    {
        Http::fake([
            '*/router/translate-path*' => Http::response([
                'jsonapi' => ['individual' => 'https://drupal.test/jsonapi/taxonomy_term/topic/'.self::UUID, 'resourceName' => 'taxonomy_term--topic'],
                'entity' => ['bundle' => 'topic'],
            ]),
            '*/jsonapi/taxonomy_term/topic/*' => Http::response(['data' => $this->resource('taxonomy_term--topic', 'a1', ['name' => 'Jane'])]),
        ]);

        $topic = $this->fetcher()->item('topic', 'jane');

        $this->assertSame('Jane', $topic->name);
        $this->assertStringContainsString('path=/topic/jane', Http::recorded()[0][0]->url());
    }

    public function test_item_of_another_resource_kind_is_not_found(): void
    {
        Http::fake(['*/router/translate-path*' => Http::response([
            'jsonapi' => ['individual' => 'https://drupal.test/jsonapi/node/talk/'.self::UUID, 'resourceName' => 'node--talk'],
            'entity' => ['bundle' => 'talk'],
        ])]);

        $this->expectException(DrupalNotFound::class);

        $this->fetcher()->item('speaker', 'a-talk');
    }

    public function test_listing_uses_the_declared_collection_sort_page_size_and_includes(): void
    {
        Http::fake(['*' => Http::response(['data' => [$this->resource('node--talk', 'b1', ['title' => 'A'])]])]);

        $listing = $this->fetcher()->all('talk', 2);

        $this->assertCount(1, $listing->items);
        [$query] = $this->queries();
        $this->assertSame('/jsonapi/talk', $query['_path']);
        $this->assertSame('-created', $query['sort']);
        $this->assertSame('15', $query['page']['limit']);
        $this->assertSame('15', $query['page']['offset']);
        $this->assertSame('cover.field_media_image,speaker,topic,slides_resources,recording_resources', $query['include'] ?? '');
    }

    public function test_listing_can_be_narrowed_by_a_content_relation(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->fetcher()->all('talk', 1, 3, 'topics', self::UUID);

        [$query] = $this->queries();
        $this->assertSame(self::UUID, $query['filter']['topic.id']);
        $this->assertSame('3', $query['page']['limit']);
    }

    public function test_narrowing_by_a_value_relation_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fetcher()->all('talk', 1, 3, 'cover', self::UUID);
    }

    public function test_a_listing_hides_entries_that_fail_a_condition(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            $this->resource('taxonomy_term--speaker', 'a1', ['title' => 'Has talks', 'talk_count' => '2']),
            $this->resource('taxonomy_term--speaker', 'a2', ['title' => 'None', 'talk_count' => '0']),
        ]])]);

        $listing = $this->fetcher()->all('speaker', 1);

        $this->assertSame(['Has talks'], $listing->items->map(fn ($speaker) => $speaker->name)->all());
    }

    public function test_a_filterable_condition_is_sent_upstream_instead_of_tested_in_php(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['speaker']['conditions'][0]['filterable'] = true;
        Http::fake(['*' => Http::response(['data' => [
            $this->resource('taxonomy_term--speaker', 'a2', ['title' => 'None', 'talk_count' => '0']),
        ]])]);

        $listing = $this->fetcher($config)->all('speaker', 1);

        [$query] = $this->queries();
        $this->assertSame('>', $query['filter']['talk_count']['condition']['operator']);
        $this->assertSame(['None'], $listing->items->map(fn ($speaker) => $speaker->name)->all());
    }

    public function test_a_type_without_a_page_size_cannot_be_listed_without_a_limit(): void
    {
        $this->expectException(LogicException::class);

        $this->fetcher()->all('track', 1);
    }

    public function test_options_are_the_named_entries_with_primary_items_sorted_by_name(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            $this->resource('taxonomy_term--tracks', 't2', ['name' => 'zebra', 'talk_count' => 1]),
            $this->resource('taxonomy_term--tracks', 't1', ['name' => 'Africa &amp; Asia', 'talk_count' => 4]),
            $this->resource('taxonomy_term--tracks', 't3', ['name' => 'Empty', 'talk_count' => 0]),
        ]])]);

        $this->assertSame(['t1' => 'Africa & Asia', 't2' => 'zebra'], $this->fetcher()->options('track'));
        $this->assertSame('/jsonapi/track', $this->queries()[0]['_path']);
    }

    public function test_a_reference_is_fetched_from_its_own_source(): void
    {
        Http::fake(['reviews.test/*' => Http::response(['data' => $this->resource('node--article', self::UUID, ['title' => 'Review', 'path' => '/r'])])]);

        $review = $this->fetcher()->reference('review', self::UUID);

        $this->assertSame('Review', $review->title);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://reviews.test/api/node/article/'));
    }

    public function test_a_reference_fails_soft(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertNull($this->fetcher()->reference('review', self::UUID));
        $this->assertNull($this->fetcher()->reference('review', null));
    }
}
