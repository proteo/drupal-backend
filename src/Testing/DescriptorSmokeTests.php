<?php

namespace Proteo\DrupalBackend\Testing;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\DescriptorFactory;
use Proteo\DrupalBackend\Descriptor\DescriptorSchema;
use Proteo\DrupalBackend\Descriptor\DescriptorValidator;
use Proteo\DrupalBackend\Descriptor\Relation;
use Proteo\DrupalBackend\Descriptor\RelationKind;
use Proteo\DrupalBackend\Search\SearchBackend;

/**
 * Smoke tests generated from a project's descriptor, for its own test suite.
 *
 * Use it in a Laravel test case. The descriptor comes from `config('drupal-backend')`;
 * override `descriptorConfig()` to test another one. Page tests skip until the
 * routes they exercise are registered, then run on their own.
 */
trait DescriptorSmokeTests
{
    use FakesDrupal;

    /**
     * @return array<string, mixed>
     */
    protected function descriptorConfig(): array
    {
        return config('drupal-backend');
    }

    protected function setUpDescriptorSmokeTests(): void
    {
        config(['drupal-backend' => $this->descriptorConfig()]);
        $this->app->forgetInstance(Descriptor::class);
    }

    public function test_the_descriptor_passes_offline_validation(): void
    {
        $descriptor = (new DescriptorFactory)->make($this->descriptorConfig());

        $this->assertSame([], (new DescriptorValidator)->problems($descriptor, app(SearchBackend::class)->capabilities()));

        // Unknown keys are ignored by the package, so they warn instead of failing the test.
        foreach (DescriptorSchema::unknownKeys($this->descriptorConfig()) as $problem) {
            fwrite(STDERR, "\nWARNING drupal-backend descriptor: $problem\n");
        }
    }

    public function test_item_pages_render_their_entity(): void
    {
        $types = array_filter(
            $this->drupalDescriptor()->contentTypes,
            fn (ContentType $type): bool => $type->served && Route::has($type->key),
        );
        $this->skipWhen($types === [], 'No item routes are registered yet.');

        foreach ($types as $type) {
            $entity = $this->fakeEntity($type->key);
            $alias = basename($entity[0]['attributes']['path']);
            $this->fakeSingleItem($type, $entity);

            $this->get(route($type->key, ['alias' => $alias]))
                ->assertOk()
                ->assertViewHas($type->key, fn (mixed $viewed): bool => $viewed instanceof Entity && $viewed->type === $type->key);
        }
    }

    public function test_listing_pages_render(): void
    {
        $types = array_filter(
            $this->drupalDescriptor()->contentTypes,
            fn (ContentType $type): bool => $type->hasListing() && Route::has($type->key.'.index'),
        );
        $this->skipWhen($types === [], 'No listing routes are registered yet.');

        foreach ($types as $type) {
            $this->resetHttpFakes();
            Http::fake(['*' => Http::response($this->fakeCollection($type->key, 2))]);

            $this->get(route($type->key.'.index'))->assertOk();
        }
    }

    public function test_the_search_page_renders(): void
    {
        $descriptor = $this->drupalDescriptor();
        $this->skipWhen(! $descriptor->search->enabled, 'Search is disabled in this descriptor.');
        $this->skipWhen(! Route::has('search'), 'The search route is not registered yet.');

        $searchable = array_key_first($descriptor->searchableTypes());
        $this->skipWhen($searchable === null, 'No content type is searchable.');
        Http::fake(['*' => Http::response($this->fakeCollection($searchable, 2))]);

        $this->get(route('search', ['q' => 'smoke']))->assertOk();
    }

    public function test_the_related_component_renders_for_each_type_with_a_content_relation(): void
    {
        $types = array_filter($this->drupalDescriptor()->contentTypes, fn (ContentType $type): bool => $this->firstContentRelation($type) !== null);
        $this->skipWhen($types === [], 'No content type has a content relation.');

        foreach ($types as $type) {
            $this->resetHttpFakes();
            Http::fake(['*' => Http::response($this->fakeCollection($type->key, 2))]);
            $item = $this->fakeEntity($type->key);
            $entity = app(ContentMapper::class)->map($type->key, $item[0], new JsonApiDocument($this->fakeItem($item)));

            // An InvalidRelatedRequest here means the descriptor cannot serve the call; it must not be swallowed.
            Blade::render('<x-drupal::related :entity="$entity" :criteria="[$relation]"/>', ['entity' => $entity, 'relation' => $this->firstContentRelation($type)]);
        }

        $this->addToAssertionCount(1);
    }

    private function firstContentRelation(ContentType $type): ?string
    {
        return array_key_first(array_filter($type->relations, fn (Relation $relation): bool => $relation->kind === RelationKind::Content && $relation->to !== null));
    }

    public function test_decorators_return_extras_keyed_by_entity_id(): void
    {
        $types = array_filter($this->drupalDescriptor()->contentTypes, fn (ContentType $type): bool => $type->decorator !== null);
        $this->skipWhen($types === [], 'No content type declares a decorator.');

        $mapper = app(ContentMapper::class);

        foreach ($types as $type) {
            $document = new JsonApiDocument($this->fakeCollection($type->key, 2));
            $entities = array_map(fn (array $resource): Entity => $mapper->map($type->key, $resource, $document), $document->data());

            $extras = app($type->decorator)->decorate($entities);

            $this->assertSame([], array_diff(array_keys($extras), array_map(fn (Entity $entity): string => $entity->id, $entities)), "The decorator of [$type->key] must key its extras by entity id.");
        }
    }

    /**
     * Answers the alias lookup and the item request for one entity.
     *
     * @param  array{0: array<string, mixed>, 1: list<array<string, mixed>>}  $entity
     */
    private function fakeSingleItem(ContentType $type, array $entity): void
    {
        $this->resetHttpFakes();
        Http::fake(fn (Request $request) => str_contains($request->url(), 'translate-path')
            ? Http::response($this->fakeAlias($type->key, $entity[0]['id']))
            : Http::response($this->fakeItem($entity)));
    }

    /**
     * Stubs accumulate and the first match wins, so each type needs a fresh factory.
     */
    private function resetHttpFakes(): void
    {
        Http::swap(new Factory);
    }

    private function skipWhen(bool $condition, string $reason): void
    {
        if ($condition) {
            $this->markTestSkipped($reason);
        }
    }
}
