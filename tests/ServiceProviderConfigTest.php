<?php

namespace Proteo\DrupalBackend\Tests;

use Illuminate\Support\ServiceProvider;
use Proteo\DrupalBackend\DrupalBackendServiceProvider;

class ServiceProviderConfigTest extends TestCase
{
    public function test_an_app_without_its_own_drupal_config_gets_working_defaults(): void
    {
        $this->assertSame(3, config('drupal.connect_timeout'));
        $this->assertSame(10, config('drupal.timeout'));
        $this->assertSame(2, config('drupal.retries'));
        $this->assertSame(200, config('drupal.retry_delay_ms'));
        $this->assertTrue(config('drupal.cache.enabled'));
        $this->assertSame(300, config('drupal.cache.fresh'));
        $this->assertSame(86400, config('drupal.cache.stale'));
        $this->assertSame(60, config('drupal.cache.negative'));
        $this->assertNull(config('drupal.cache.store'));
    }

    public function test_the_drupal_config_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(DrupalBackendServiceProvider::class, 'drupal-backend-config');

        $this->assertContains(config_path('drupal.php'), $paths);
    }
}
