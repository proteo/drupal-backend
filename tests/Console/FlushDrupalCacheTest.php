<?php

namespace Proteo\DrupalBackend\Tests\Console;

use Illuminate\Support\Facades\Cache;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class FlushDrupalCacheTest extends DescriptorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->fixture('conference');
        $config['sources']['third'] = ['base_url' => 'https://third.test'];
        $config['sources']['default']['flush'] = ['also' => ['sources' => ['reviews']]];

        $this->app->instance(Descriptor::class, $this->descriptor($config));

        foreach (['default', 'reviews', 'third'] as $source) {
            Cache::forever(CachedDrupal::generationKey($source), 'before');
        }
    }

    /**
     * @return list<string> The Sources whose generation moved.
     */
    private function moved(): array
    {
        return array_values(array_filter(
            ['default', 'reviews', 'third'],
            fn (string $source): bool => Cache::get(CachedDrupal::generationKey($source)) !== 'before',
        ));
    }

    public function test_without_an_argument_every_source_moves_to_a_new_generation(): void
    {
        $this->artisan('drupal:flush')->expectsOutputToContain('Drupal content cache flushed')->assertSuccessful();

        $this->assertSame(['default', 'reviews', 'third'], $this->moved());
    }

    public function test_one_source_is_flushed_and_the_others_are_left_alone(): void
    {
        $this->artisan('drupal:flush third')->assertSuccessful();

        $this->assertSame(['third'], $this->moved());
    }

    public function test_the_sources_it_also_flushes_follow(): void
    {
        $this->artisan('drupal:flush default')->assertSuccessful();

        $this->assertSame(['default', 'reviews'], $this->moved());
    }

    public function test_an_unknown_source_fails(): void
    {
        $this->artisan('drupal:flush nowhere')->expectsOutputToContain('Unknown source [nowhere]')->assertFailed();

        $this->assertSame([], $this->moved());
    }
}
