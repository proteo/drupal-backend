<?php

namespace Proteo\DrupalBackend\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Proteo\DrupalBackend\DrupalBackendServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DrupalBackendServiceProvider::class];
    }
}
