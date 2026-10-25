<?php

namespace Proteo\DrupalBackend\Tests\Descriptor;

use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\DescriptorFactory;
use Proteo\DrupalBackend\Descriptor\DescriptorValidator;
use Proteo\DrupalBackend\Search\Capabilities;
use Proteo\DrupalBackend\Tests\TestCase;

abstract class DescriptorTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        return require __DIR__.'/../Fixtures/'.$name.'.php';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function descriptor(array $config): Descriptor
    {
        return (new DescriptorFactory)->make($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  ?Capabilities  $capabilities  What the bound search backend declares; null skips the rules that need it.
     * @return list<string>
     */
    protected function problems(array $config, ?Capabilities $capabilities = null): array
    {
        return (new DescriptorValidator)->problems($this->descriptor($config), $capabilities);
    }
}
