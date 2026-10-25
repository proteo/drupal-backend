<?php

namespace Proteo\DrupalBackend\Tests\Http;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Http\Middleware\VerifyDrupalWebhook;
use Proteo\DrupalBackend\Tests\TestCase;

class VerifyDrupalWebhookTest extends TestCase
{
    private const string SECRET = 'shared-secret';

    protected function defineEnvironment($app): void
    {
        // The fixture's default Source signs with SECRET and a 30 second tolerance.
        $app['config']->set('drupal-backend', require __DIR__.'/../Fixtures/conference.php');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('/hooks/test/{source}', fn () => response('', 202))->middleware(VerifyDrupalWebhook::class);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $body = '{"event":"update"}', ?int $timestamp = null, string $secret = self::SECRET): array
    {
        $timestamp ??= time();

        return [
            'X-Drupal-Timestamp' => (string) $timestamp,
            'X-Drupal-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }

    public function test_a_valid_signature_reaches_the_route(): void
    {
        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed()), '{"event":"update"}')
            ->assertStatus(202);
    }

    public function test_the_body_is_ignored(): void
    {
        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed('not json')), 'not json')
            ->assertStatus(202);
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed(secret: 'other')), '{"event":"update"}')
            ->assertUnauthorized();
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed('{"a":1}')), '{"a":2}')
            ->assertUnauthorized();
    }

    public function test_missing_headers_are_rejected(): void
    {
        $this->postJson('/hooks/test/default')->assertUnauthorized();
    }

    public function test_a_stale_timestamp_is_rejected_even_when_correctly_signed(): void
    {
        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed(timestamp: time() - 31)), '{"event":"update"}')
            ->assertUnauthorized();
    }

    public function test_a_future_timestamp_outside_tolerance_is_rejected(): void
    {
        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed(timestamp: time() + 31)), '{"event":"update"}')
            ->assertUnauthorized();
    }

    public function test_a_non_numeric_timestamp_is_rejected(): void
    {
        $headers = ['X-Drupal-Timestamp' => 'abc', 'X-Drupal-Signature' => hash_hmac('sha256', 'abc.{}', self::SECRET)];

        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($headers), '{}')->assertUnauthorized();
    }

    public function test_a_replayed_request_is_rejected(): void
    {
        $headers = $this->serverHeaders($this->signed());

        $this->call('POST', '/hooks/test/default', [], [], [], $headers, '{"event":"update"}')->assertStatus(202);
        $this->call('POST', '/hooks/test/default', [], [], [], $headers, '{"event":"update"}')->assertUnauthorized();
    }

    public function test_it_is_inert_without_a_secret(): void
    {
        config(['drupal-backend.sources.default.webhook.secret' => '']);
        $this->app->forgetInstance(Descriptor::class);

        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($this->signed(secret: '')), '{"event":"update"}')
            ->assertNotFound();
    }

    public function test_rejections_are_logged_without_the_secret_or_signature(): void
    {
        Log::spy();
        $headers = $this->signed(secret: 'other');

        $this->call('POST', '/hooks/test/default', [], [], [], $this->serverHeaders($headers), '{"event":"update"}');

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($headers): bool {
            return $message === 'Drupal webhook rejected'
                && $context['reason'] === 'signature_mismatch'
                && ! str_contains(json_encode($context), $headers['X-Drupal-Signature'])
                && ! str_contains(json_encode($context), self::SECRET);
        })->once();
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server + ['CONTENT_TYPE' => 'application/json'];
    }

    public function test_a_source_without_a_secret_is_inert_while_others_work(): void
    {
        $this->call('POST', '/hooks/test/reviews', [], [], [], $this->serverHeaders($this->signed()), '{"event":"update"}')->assertNotFound();
        $this->call('POST', '/hooks/test/unknown', [], [], [], $this->serverHeaders($this->signed()), '{"event":"update"}')->assertNotFound();
    }
}
