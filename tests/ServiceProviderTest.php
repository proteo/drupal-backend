<?php

namespace Proteo\DrupalBackend\Tests;

use Proteo\DrupalBackend\DrupalBackendServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_the_provider_is_registered(): void
    {
        $this->assertArrayHasKey(DrupalBackendServiceProvider::class, $this->app->getLoadedProviders());
    }
}
