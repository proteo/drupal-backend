<?php

namespace Proteo\DrupalBackend\Tests\Http;

use Illuminate\Support\Facades\Route;
use Proteo\DrupalBackend\Tests\TestCase;

class SearchDisabledRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('drupal-backend', require __DIR__.'/../Fixtures/article.php');
    }

    public function test_no_suggestion_route_without_search(): void
    {
        $this->assertFalse(Route::has('search.suggest'));
        $this->assertFalse(Route::has('search'));
        $this->assertTrue(Route::has('hooks.drupal'));
    }
}
