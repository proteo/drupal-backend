<?php

namespace Proteo\DrupalBackend\Tests\Smoke;

use Proteo\DrupalBackend\Testing\DescriptorSmokeTests;
use Proteo\DrupalBackend\Tests\TestCase;

class ConferenceFixtureSmokeTest extends TestCase
{
    use DescriptorSmokeTests;

    protected function descriptorConfig(): array
    {
        return require __DIR__.'/../Fixtures/conference.php';
    }
}
