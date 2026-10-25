<?php

namespace Proteo\DrupalBackend\Tests\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Client\CollectionRequest;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Client\DrupalResponse;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Exceptions\DrupalUnavailable;
use Proteo\DrupalBackend\Tests\TestCase;

class DrupalPoolTest extends TestCase
{
    private const INDEX = 'index/default';

    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.cache.enabled' => false]);
    }

    private function client(int $retries = 2): DrupalClient
    {
        return new DrupalClient('https://drupal.test', '/jsonapi', retries: $retries, retryDelayMs: 0);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    private function document(array $data, ?int $count = null): array
    {
        return ['data' => $data] + ($count === null ? [] : ['meta' => ['count' => $count]]);
    }

    private function requestFor(string $bundle, int $limit = 1): CollectionRequest
    {
        return new CollectionRequest(self::INDEX, ['filter[bundle]' => $bundle], $limit);
    }

    public function test_total_comes_from_meta_count(): void
    {
        Http::fake(['*' => Http::response($this->document([['id' => 'a']], 42))]);

        $response = $this->client()->collection(self::INDEX, [], 1);

        $this->assertSame(42, $response->total());
    }

    public function test_total_falls_back_to_the_number_of_returned_items(): void
    {
        Http::fake(['*' => Http::response($this->document([['id' => 'a'], ['id' => 'b']]))]);

        $this->assertSame(2, $this->client()->collection(self::INDEX, [], 15)->total());
    }

    public function test_pool_returns_responses_in_input_order(): void
    {
        Http::fake(fn (Request $request) => Http::response(
            $this->document([], (int) filter_var($request['filter']['bundle'], FILTER_SANITIZE_NUMBER_INT)),
        ));

        $responses = $this->client()->pool([
            $this->requestFor('b1'), $this->requestFor('b2'), $this->requestFor('b3'),
        ]);

        $this->assertContainsOnlyInstancesOf(DrupalResponse::class, $responses);
        $this->assertSame([1, 2, 3], array_map(fn (DrupalResponse $r): int => $r->total(), $responses));
        Http::assertSentCount(3);
    }

    public function test_pool_sends_limit_and_offset(): void
    {
        Http::fake(['*' => Http::response($this->document([]))]);

        $this->client()->pool([new CollectionRequest(self::INDEX, [], 15, 30)]);

        Http::assertSent(fn (Request $r): bool => $r['page']['limit'] === '15' && $r['page']['offset'] === '30');
    }

    public function test_empty_pool_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame([], $this->client()->pool([]));
        Http::assertNothingSent();
    }

    public function test_pool_retries_server_errors(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 500)
            ->push($this->document([], 7))]);

        $responses = $this->client()->pool([$this->requestFor('talk')]);

        $this->assertSame(7, $responses[0]->total());
        Http::assertSentCount(2);
    }

    public function test_pool_maps_exhausted_server_errors_to_unavailable(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->expectException(DrupalUnavailable::class);

        $this->client(retries: 1)->pool([$this->requestFor('talk')]);
    }

    public function test_pool_maps_connection_failures_to_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->expectException(DrupalUnavailable::class);

        $this->client(retries: 0)->pool([$this->requestFor('talk')]);
    }

    public function test_pool_maps_too_many_requests_to_unavailable(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $this->expectException(DrupalUnavailable::class);

        $this->client()->pool([$this->requestFor('talk')]);
    }

    public function test_pool_maps_forbidden_and_not_found_to_not_found(): void
    {
        Http::fake(['*' => Http::response('', 403)]);

        $this->expectException(DrupalNotFound::class);

        $this->client()->pool([$this->requestFor('talk')]);
    }

    public function test_pool_maps_client_errors_to_bad_response(): void
    {
        Http::fake(['*' => Http::response('', 400)]);

        $this->expectException(DrupalBadResponse::class);

        $this->client()->pool([$this->requestFor('talk')]);
    }

    public function test_pool_rejects_a_document_without_data(): void
    {
        Http::fake(['*' => Http::response(['meta' => ['count' => 1]])]);

        $this->expectException(DrupalBadResponse::class);

        $this->client()->pool([$this->requestFor('talk')]);
    }

    public function test_one_failing_request_fails_the_whole_pool(): void
    {
        Http::fake(fn (Request $request) => $request['filter']['bundle'] === 'bad'
            ? Http::response('', 400)
            : Http::response($this->document([], 1)));

        $this->expectException(DrupalBadResponse::class);

        $this->client()->pool([$this->requestFor('ok'), $this->requestFor('bad'), $this->requestFor('ok')]);
    }

    public function test_search_serves_a_repeat_from_cache_within_the_window(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(['*' => Http::response($this->document([['id' => 'a']], 9))]);
        $cached = new CachedDrupal($this->client());

        $first = $cached->search([$this->requestFor('talk')]);
        $second = $cached->search([$this->requestFor('talk')]);

        Http::assertSentCount(1);
        $this->assertSame(9, $first[0]->total());
        $this->assertSame(9, $second[0]->total());
        $this->assertSame($first[0]->data(), $second[0]->data());
    }

    public function test_search_requests_only_the_cache_misses(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(fn (Request $request) => Http::response($this->document([], $request['filter']['bundle'] === 'a' ? 1 : 2)));
        $cached = new CachedDrupal($this->client());
        $cached->search([$this->requestFor('a')]);

        $responses = $cached->search([$this->requestFor('b'), $this->requestFor('a')]);

        Http::assertSentCount(2);
        $this->assertSame([2, 1], array_map(fn (DrupalResponse $r): int => $r->total(), $responses));
    }

    public function test_search_entries_expire_after_the_fresh_window(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(['*' => Http::response($this->document([], 1))]);
        $cached = new CachedDrupal($this->client());

        $cached->search([$this->requestFor('talk')]);
        $this->travel(61)->seconds();
        $cached->search([$this->requestFor('talk')]);

        Http::assertSentCount(2);
    }

    public function test_flush_clears_search_entries(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(['*' => Http::response($this->document([], 1))]);
        $cached = new CachedDrupal($this->client());

        $cached->search([$this->requestFor('talk')]);
        $cached->flush();
        $cached->search([$this->requestFor('talk')]);

        Http::assertSentCount(2);
    }

    public function test_search_is_not_cached_when_the_window_is_zero(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(['*' => Http::response($this->document([], 1))]);
        $cached = new CachedDrupal($this->client(), searchWindow: 0);

        $cached->search([$this->requestFor('talk')]);
        $cached->search([$this->requestFor('talk')]);

        Http::assertSentCount(2);
    }

    public function test_search_is_not_cached_when_caching_is_disabled(): void
    {
        config(['drupal.cache.enabled' => false]);
        Http::fake(['*' => Http::response($this->document([], 1))]);
        $cached = new CachedDrupal($this->client());

        $cached->search([$this->requestFor('talk')]);
        $cached->search([$this->requestFor('talk')]);

        Http::assertSentCount(2);
    }

    public function test_search_errors_are_never_cached(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(['*' => Http::sequence()->push('', 400)->push($this->document([], 3))]);
        $cached = new CachedDrupal($this->client());

        try {
            $cached->search([$this->requestFor('talk')]);
            $this->fail('Expected a bad response.');
        } catch (DrupalBadResponse) {
            // Expected.
        }

        $this->assertSame(3, $cached->search([$this->requestFor('talk')])[0]->total());
    }

    public function test_search_keys_differ_by_query_limit_and_offset(): void
    {
        config(['drupal.cache.enabled' => true]);
        Http::fake(['*' => Http::response($this->document([], 1))]);
        $cached = new CachedDrupal($this->client());

        $cached->search([
            new CollectionRequest(self::INDEX, ['a' => '1'], 15),
            new CollectionRequest(self::INDEX, ['a' => '2'], 15),
            new CollectionRequest(self::INDEX, ['a' => '1'], 1),
            new CollectionRequest(self::INDEX, ['a' => '1'], 15, 15),
        ]);

        Http::assertSentCount(4);
    }

    public function test_existing_collection_cache_is_unaffected_by_the_search_window(): void
    {
        config(['drupal.cache.enabled' => true, 'drupal.cache.search' => 0, 'drupal.cache.fresh' => 300]);
        Http::fake(['*' => Http::response($this->document([['id' => 'a']]))]);
        $cached = new CachedDrupal($this->client());

        $cached->collection('node/talk', [], 15);
        $cached->collection('node/talk', [], 15);

        Http::assertSentCount(1);
    }
}
