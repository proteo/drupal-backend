<?php

namespace Proteo\DrupalBackend\Tests\Livewire;

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Proteo\DrupalBackend\Livewire\Listing;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\TestCase;

class ListingTest extends TestCase
{
    use FakesDrupal;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('drupal-backend', require __DIR__.'/../Fixtures/conference.php');
    }

    public function test_the_package_view_lists_the_items_with_links(): void
    {
        Http::fake(['*' => Http::response($this->fakeCollection('talk', 2))]);

        Livewire::test(Listing::class, ['contentType' => 'talk'])
            ->assertSeeHtml('<ul>')
            ->assertSeeHtml('<li');
    }

    public function test_the_component_is_addressable_by_its_namespace(): void
    {
        Http::fake(['*' => Http::response($this->fakeCollection('talk', 1))]);

        $this->assertSame('drupal::listing', Livewire::new('drupal::listing')->getName());
    }
}
