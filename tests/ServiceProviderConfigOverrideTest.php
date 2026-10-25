<?php

namespace Proteo\DrupalBackend\Tests;

use Proteo\DrupalBackend\DrupalBackendServiceProvider;

class ServiceProviderConfigOverrideTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // What a project's own config/drupal.php loads before providers boot.
        $app['config']->set('drupal', ['timeout' => 30, 'cache' => ['enabled' => false]]);

        // Testbench loads this after the providers registered, unlike a real config file.
        (new DrupalBackendServiceProvider($app))->register();
    }

    public function test_the_project_file_overrides_defaults_and_unset_keys_keep_theirs(): void
    {
        $this->assertSame(30, config('drupal.timeout'));
        $this->assertFalse(config('drupal.cache.enabled'));
        $this->assertSame(2, config('drupal.retries'));
        $this->assertSame(300, config('drupal.cache.fresh'));
    }
}
