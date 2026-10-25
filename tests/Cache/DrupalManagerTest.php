<?php

namespace Proteo\DrupalBackend\Tests\Cache;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class DrupalManagerTest extends DescriptorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'drupal.cache.enabled' => true, 'drupal.cache.fresh' => 300, 'drupal.cache.stale' => 86400,
            'drupal.timeout' => 5, 'drupal.connect_timeout' => 2, 'drupal.retries' => 0, 'drupal.retry_delay_ms' => 0,
        ]);
    }

    private function manager(): DrupalManager
    {
        return new DrupalManager($this->descriptor($this->fixture('conference')));
    }

    public function test_a_source_is_resolved_once_and_the_default_is_used_when_none_is_named(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->source('default'), $manager->source());
        $this->assertNotSame($manager->source('default'), $manager->source('reviews'));
    }

    public function test_each_source_talks_to_its_own_backend(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $manager = $this->manager();

        $manager->source()->collection('talk', [], 5);
        $manager->source('reviews')->collection('node/article', [], 5);

        $urls = Http::recorded()->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_HOST).parse_url($pair[0]->url(), PHP_URL_PATH))->all();
        $this->assertSame(['drupal.test/jsonapi/talk', 'reviews.test/api/node/article'], $urls);
    }

    public function test_flushing_one_source_keeps_the_cached_content_of_the_others(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $manager = $this->manager();

        foreach (['default', 'reviews'] as $source) {
            $manager->source($source)->collection('same', [], 5);
        }
        Http::assertSentCount(2);

        $manager->flush('reviews');

        foreach (['default', 'reviews'] as $source) {
            $manager->source($source)->collection('same', [], 5);
        }

        // Only the flushed Source went back to the backend.
        Http::assertSentCount(3);
        $this->assertSame('reviews.test', parse_url(Http::recorded()->last()[0]->url(), PHP_URL_HOST));
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager()->source('nowhere');
    }
}
