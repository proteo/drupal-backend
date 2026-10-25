<?php

namespace Proteo\DrupalBackend\Tests\Client;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Exceptions\DrupalUnavailable;
use Proteo\DrupalBackend\Tests\TestCase;

class DrupalClientTest extends TestCase
{
    private function client(int $retries = 2): DrupalClient
    {
        return new DrupalClient('https://drupal.test', '/jsonapi', retries: $retries, retryDelayMs: 0);
    }

    public function test_collection_reports_whether_there_is_a_next_page(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'links' => ['next' => ['href' => 'x']]])]);

        $this->assertTrue($this->client()->collection('node/talk', [], 15)->hasMore);
    }

    public function test_collection_sends_query_limit_and_offset(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $response = $this->client()->collection('node/talk', ['sort' => '-created'], 15, 30);

        $this->assertFalse($response->hasMore);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://drupal.test/jsonapi/node/talk?')
            && $r['sort'] === '-created' && $r['page']['limit'] === '15' && $r['page']['offset'] === '30');
    }

    public function test_item_never_has_more(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'a'], 'links' => ['next' => ['href' => 'x']]])]);

        $this->assertFalse($this->client()->item('node/talk', 'a')->hasMore);
    }

    public function test_single_requests_retry_server_errors(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 500)->push(['data' => []])]);

        $this->client()->collection('node/talk', [], 1);

        Http::assertSentCount(2);
    }

    public function test_single_requests_map_exhausted_server_errors_to_unavailable(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->expectException(DrupalUnavailable::class);

        $this->client(retries: 0)->collection('node/talk', [], 1);
    }

    public function test_single_requests_map_missing_resources_to_not_found(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->expectException(DrupalNotFound::class);

        $this->client()->item('node/talk', 'a');
    }

    public function test_single_requests_map_a_document_without_data_to_bad_response(): void
    {
        Http::fake(['*' => Http::response(['no' => 'data'])]);

        $this->expectException(DrupalBadResponse::class);

        $this->client()->item('node/talk', 'a');
    }
}
