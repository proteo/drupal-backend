<?php

namespace Proteo\DrupalBackend\Tests\Cache;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Exceptions\DrupalUnavailable;
use Proteo\DrupalBackend\Tests\TestCase;

class CachedDrupalTest extends TestCase
{
    private const string UUID = 'e34b1bd7-3e4a-4fd4-95e3-1eb97741f0ce';

    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.cache.enabled' => true, 'drupal.cache.fresh' => 300, 'drupal.cache.stale' => 86400, 'drupal.cache.negative' => 60]);
    }

    private function cached(): CachedDrupal
    {
        return new CachedDrupal(new DrupalClient('https://drupal.test', '/jsonapi', retries: 0, retryDelayMs: 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function translation(string $uuid = self::UUID): array
    {
        return [
            'jsonapi' => [
                'individual' => 'https://drupal.test/jsonapi/node/talk/'.$uuid,
                'resourceName' => 'node--talk',
            ],
            'entity' => ['bundle' => 'talk'],
        ];
    }

    public function test_collection_is_served_from_cache_on_a_repeat(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'a']], 'links' => ['next' => ['href' => 'x']]])]);
        $cached = $this->cached();

        $first = $cached->collection('node/talk', ['sort' => 'title'], 15);
        $second = $cached->collection('node/talk', ['sort' => 'title'], 15);

        Http::assertSentCount(1);
        $this->assertTrue($second->hasMore);
        $this->assertSame($first->data(), $second->data());
    }

    public function test_collection_keys_differ_by_resource_query_limit_and_offset(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $cached = $this->cached();

        $cached->collection('node/talk', [], 15);
        $cached->collection('taxonomy_term/speaker', [], 15);
        $cached->collection('node/talk', ['sort' => 'title'], 15);
        $cached->collection('node/talk', [], 10);
        $cached->collection('node/talk', [], 15, 15);

        Http::assertSentCount(5);
    }

    public function test_item_is_served_from_cache_and_never_reports_more(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => self::UUID], 'links' => ['next' => ['href' => 'x']]])]);
        $cached = $this->cached();

        $cached->item('node/talk', self::UUID, ['include' => 'a']);
        $second = $cached->item('node/talk', self::UUID, ['include' => 'a']);

        Http::assertSentCount(1);
        $this->assertFalse($second->hasMore);
    }

    public function test_nothing_is_cached_when_caching_is_disabled(): void
    {
        config(['drupal.cache.enabled' => false]);
        Http::fake(['*' => Http::response(['data' => []])]);
        $cached = $this->cached();

        $cached->collection('node/talk', [], 15);
        $cached->collection('node/talk', [], 15);
        $cached->item('node/talk', self::UUID);
        $cached->item('node/talk', self::UUID);

        Http::assertSentCount(4);
    }

    public function test_flush_makes_the_next_call_refetch(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $cached = $this->cached();

        $cached->collection('node/talk', [], 15);
        $cached->flush();
        $cached->collection('node/talk', [], 15);

        Http::assertSentCount(2);
    }

    public function test_fresh_entries_expire_into_a_refetch_after_the_stale_window(): void
    {
        config(['drupal.cache.fresh' => 60, 'drupal.cache.stale' => 60]);
        Http::fake(['*' => Http::response(['data' => []])]);
        $cached = $this->cached();

        $cached->collection('node/talk', [], 15);
        $this->travel(121)->seconds();
        $cached->collection('node/talk', [], 15);

        Http::assertSentCount(2);
    }

    public function test_a_resolved_path_is_cached(): void
    {
        Http::fake(['*' => Http::response($this->translation())]);
        $cached = $this->cached();

        $cached->resolvePath('/talk/a-talk');
        $path = $cached->resolvePath('/talk/a-talk');

        Http::assertSentCount(1);
        $this->assertSame('node/talk/'.self::UUID, $path->endpoint);
        $this->assertSame('node--talk', $path->resourceName);
        $this->assertSame('talk', $path->bundle);
    }

    public function test_an_unknown_alias_is_remembered_as_missing(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $cached = $this->cached();

        foreach ([1, 2] as $_) {
            try {
                $cached->resolvePath('/talk/nope');
                $this->fail('Expected DrupalNotFound.');
            } catch (DrupalNotFound) {
            }
        }

        Http::assertSentCount(1);
    }

    public function test_a_missing_alias_is_retried_after_the_negative_window(): void
    {
        config(['drupal.cache.negative' => 60]);
        Http::fake(['*' => Http::sequence()->push('', 404)->push($this->translation())]);
        $cached = $this->cached();

        try {
            $cached->resolvePath('/talk/later');
        } catch (DrupalNotFound) {
        }
        $this->travel(61)->seconds();

        $this->assertSame('node--talk', $cached->resolvePath('/talk/later')->resourceName);
    }

    public function test_an_unavailable_backend_is_not_remembered_as_missing(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push($this->translation())]);
        $cached = $this->cached();

        try {
            $cached->resolvePath('/talk/a-talk');
            $this->fail('Expected DrupalUnavailable.');
        } catch (DrupalUnavailable) {
        }

        $this->assertSame('node--talk', $cached->resolvePath('/talk/a-talk')->resourceName);
        Http::assertSentCount(2);
    }

    public function test_flush_also_clears_remembered_misses(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 404)->push($this->translation())]);
        $cached = $this->cached();

        try {
            $cached->resolvePath('/talk/a-talk');
        } catch (DrupalNotFound) {
        }
        $cached->flush();

        $this->assertSame('talk', $cached->resolvePath('/talk/a-talk')->bundle);
    }

    public function test_the_alias_is_sent_to_the_router_unencoded_in_its_slashes(): void
    {
        Http::fake(['*' => Http::response($this->translation())]);

        $this->cached()->resolvePath('/talk/a b');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://drupal.test/router/translate-path?path=/talk/a%20b');
    }
}
