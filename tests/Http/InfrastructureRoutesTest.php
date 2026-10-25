<?php

namespace Proteo\DrupalBackend\Tests\Http;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\TestCase;

class InfrastructureRoutesTest extends TestCase
{
    use FakesDrupal;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('drupal-backend', require __DIR__.'/../Fixtures/conference.php');
        $app['config']->set('drupal-backend.sources.reviews.webhook.secret', 'reviews-secret');
        $app['config']->set('drupal-backend.sources.default.flush.also.sources', ['reviews']);
        $app['config']->set('drupal-backend.limits.search_suggest', 2);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $secret = 'shared-secret'): array
    {
        $timestamp = (string) time();

        return [
            'HTTP_X_DRUPAL_TIMESTAMP' => $timestamp,
            'HTTP_X_DRUPAL_SIGNATURE' => hash_hmac('sha256', $timestamp.'.{}', $secret),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function test_the_default_source_answers_at_the_plain_path_and_flushes_its_dependents(): void
    {
        Cache::forever(CachedDrupal::generationKey('default'), 'before');
        Cache::forever(CachedDrupal::generationKey('reviews'), 'before');

        $this->call('POST', '/hooks/drupal', [], [], [], $this->signed(), '{}')->assertStatus(202);

        $this->assertNotSame('before', Cache::get(CachedDrupal::generationKey('default')));
        $this->assertNotSame('before', Cache::get(CachedDrupal::generationKey('reviews')));
    }

    public function test_another_source_answers_at_its_own_path_with_its_own_secret(): void
    {
        Cache::forever(CachedDrupal::generationKey('default'), 'before');

        $this->call('POST', '/hooks/drupal/reviews', [], [], [], $this->signed('reviews-secret'), '{}')->assertStatus(202);
        $this->call('POST', '/hooks/drupal/reviews', [], [], [], $this->signed('shared-secret'), '{}')->assertUnauthorized();

        $this->assertSame('before', Cache::get(CachedDrupal::generationKey('default')));
    }

    public function test_the_webhook_needs_no_csrf_token(): void
    {
        $this->assertSame(['throttle:drupal-webhook', 'Proteo\DrupalBackend\Http\Middleware\VerifyDrupalWebhook'], Route::getRoutes()->getByName('hooks.drupal')->gatherMiddleware());
    }

    public function test_the_search_page_renders_the_package_view_with_its_component(): void
    {
        $this->assertTrue(Route::has('search'));

        $this->get(route('search'))->assertOk()->assertViewIs('drupal::search')->assertSeeLivewire('drupal::search');
    }

    public function test_the_suggestion_route_exists_only_when_search_is_enabled(): void
    {
        $this->assertTrue(Route::has('search.suggest'));
    }

    public function test_limits_configure_the_named_limiters(): void
    {
        Http::fake(['*' => Http::response($this->fakeCollection('talk', 0))]);
        RateLimiter::clear('search-suggest');

        $this->getJson('/search/suggest?q=ab')->assertOk();
        $this->getJson('/search/suggest?q=ab')->assertOk();
        $this->getJson('/search/suggest?q=ab')->assertTooManyRequests();
    }
}
