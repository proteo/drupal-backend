<?php

namespace Proteo\DrupalBackend\Tests\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\TestCase;

class ContentRoutesTest extends TestCase
{
    use FakesDrupal;

    protected function defineEnvironment($app): void
    {
        // The web middleware group encrypts cookies.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('drupal-backend', require __DIR__.'/../Fixtures/conference.php');
        $app['config']->set('drupal-backend.content_types.page.routes', ['about' => '/about']);
    }

    public function test_primary_and_facet_types_get_item_and_listing_routes(): void
    {
        $this->assertSame('talk/{alias}', Route::getRoutes()->getByName('talk')->uri());
        $this->assertSame('talks', Route::getRoutes()->getByName('talk.index')->uri());
        $this->assertTrue(Route::has('speaker'));
    }

    public function test_the_item_route_renders_the_package_view_with_the_entity(): void
    {
        [$talk, $included] = $this->fakeEntity('talk', ['title' => 'Revolution Now']);
        Http::fake(fn ($request) => str_contains($request->url(), 'translate-path')
            ? Http::response($this->fakeAlias('talk', $talk['id']))
            : Http::response($this->fakeItem([$talk, $included])));

        $this->get(route('talk', basename($talk['attributes']['path'])))
            ->assertOk()
            ->assertViewIs('drupal::item')
            ->assertViewHas('talk', fn (mixed $viewed): bool => $viewed instanceof Entity && $viewed->title === 'Revolution Now')
            ->assertSee('Revolution Now');
    }

    public function test_a_project_view_wins_over_the_package_view(): void
    {
        $this->app['view']->addNamespace('project', __DIR__.'/../Fixtures/views');
        $this->app['config']->set('drupal-backend.content_types.talk.views.index', 'project::listing');
        $this->app->forgetInstance(Descriptor::class);

        $this->get(route('talk.index'))->assertOk()->assertSee('project listing');
    }

    public function test_a_malformed_alias_is_not_routed(): void
    {
        $this->get('/talk/not_an_alias')->assertNotFound();
    }

    public function test_a_page_route_is_named_after_its_slug_and_renders_the_package_view(): void
    {
        [$page, $included] = $this->fakeEntity('page', ['title' => 'About Us']);
        Http::fake(fn ($request) => str_contains($request->url(), 'translate-path')
            ? Http::response($this->fakeAlias('page', $page['id']))
            : Http::response($this->fakeItem([$page, $included])));

        $this->assertSame(url('/about'), route('page.about'));

        $this->get('/about')
            ->assertOk()
            ->assertViewIs('drupal::page')
            ->assertViewHas('page', fn (mixed $viewed): bool => $viewed instanceof Entity && $viewed->type === 'page')
            ->assertSee('About Us');
    }
}
