<?php

namespace Proteo\DrupalBackend\Tests\Testing;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Content\Image;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class FakesDrupalTest extends DescriptorTestCase
{
    use FakesDrupal;

    private Descriptor $descriptor;

    protected function drupalDescriptor(): Descriptor
    {
        return $this->descriptor;
    }

    private function use(string $fixture): ContentMapper
    {
        $this->descriptor = $this->descriptor($this->fixture($fixture));

        return new ContentMapper($this->descriptor, new EntityFactory($this->descriptor), 'https://app.test');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        return ['conference' => ['conference'], 'article' => ['article']];
    }

    #[DataProvider('fixtures')]
    public function test_every_content_type_of_a_fixture_maps_back_to_an_entity(string $fixture): void
    {
        $mapper = $this->use($fixture);

        foreach ($this->descriptor->contentTypes as $type) {
            $entity = $this->fakeEntity($type->key);
            $document = new JsonApiDocument($this->fakeItem($entity));

            $mapped = $mapper->map($type->key, $entity[0], $document);

            $this->assertSame($type->key, $mapped->type);
            foreach ($type->fields as $name => $field) {
                $this->assertNotNull($mapped->$name, "$type->key.$name");
            }
        }
    }

    public function test_relations_resolve_to_one_default_resource_each(): void
    {
        $mapper = $this->use('conference');
        $entity = $this->fakeEntity('talk');

        $talk = $mapper->map('talk', $entity[0], new JsonApiDocument($this->fakeItem($entity)));

        $this->assertCount(1, $talk->speakers);
        $this->assertSame('speaker', $talk->speakers[0]->type);
        $this->assertCount(1, $talk->tracks);
        $this->assertSame('resource', $talk->slide_resources[0]->type);
        $this->assertInstanceOf(Image::class, $talk->cover);
    }

    public function test_values_related_resources_and_raw_attributes_override_the_defaults(): void
    {
        $mapper = $this->use('conference');
        $speaker = $this->fakeEntity('speaker', ['name' => 'Jane', 'talk_count' => 4]);
        $entity = $this->fakeEntity(
            'talk',
            ['title' => 'Custom', 'id' => 'fixed-id', 'path' => '/custom'],
            ['speakers' => [$speaker], 'tracks' => [], 'cover' => ['alt' => 'Alt', 'uri' => '/x.jpg', 'styles' => ['medium' => 'https://drupal.test/m.jpg']], 'slide_resources' => [$this->fakeEntity('resource', ['href' => 'https://www.a.test/b'])]],
            ['review_reference' => 'rev-1'],
        );

        $talk = $mapper->map('talk', $entity[0], new JsonApiDocument($this->fakeItem($entity)));

        $this->assertSame(['fixed-id', 'Custom', '/custom', 'rev-1'], [$talk->id, $talk->title, $talk->path, $talk->review_id]);
        $this->assertSame('Jane', $talk->speakers[0]->name);
        $this->assertSame(4, $talk->speakers[0]->talk_count);
        $this->assertSame([], $talk->tracks);
        $this->assertSame('Alt', $talk->cover->alt);
        $this->assertSame('https://drupal.test/m.jpg', $talk->cover->urlFor('medium'));
        $this->assertSame('https://www.a.test/b', $talk->slide_resources[0]->href);
    }

    public function test_a_single_relation_is_one_resource_and_can_be_left_out(): void
    {
        $mapper = $this->use('article');
        $entity = $this->fakeEntity('article');
        $without = $this->fakeEntity('article', [], ['genre' => null]);

        $this->assertSame('genre', $mapper->map('article', $entity[0], new JsonApiDocument($this->fakeItem($entity)))->genre->type);
        $this->assertNull($mapper->map('article', $without[0], new JsonApiDocument($this->fakeItem($without)))->genre);
    }

    public function test_nested_field_sources_become_nested_attributes(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['fields']['content'] = ['source' => 'description.value', 'type' => 'html'];
        $this->descriptor = $this->descriptor($config);

        $this->assertSame('<p>Content 1</p>', $this->fakeEntity('talk')[0]['attributes']['description']['value']);
    }

    public function test_unknown_values_and_relations_are_rejected(): void
    {
        $this->use('conference');

        try {
            $this->fakeEntity('talk', ['nope' => 1]);
            $this->fail('Expected an unknown field to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->fakeEntity('talk', [], ['nope' => []]);
    }

    public function test_a_collection_holds_the_requested_entities_and_deduplicates_included(): void
    {
        $this->use('conference');
        $topic = $this->fakeEntity('topic');
        $talks = [$this->fakeEntity('talk', [], ['topics' => [$topic]]), $this->fakeEntity('talk', [], ['topics' => [$topic]])];

        $document = $this->fakeCollection('talk', $talks, hasMore: true);

        $this->assertCount(2, $document['data']);
        $this->assertCount(1, array_filter($document['included'], fn (array $r): bool => $r['type'] === 'taxonomy_term--topic'));
        $this->assertSame([], $document['meta']);
        $this->assertSame('https://drupal.test/next', $document['links']['next']['href']);
        $this->assertCount(3, $this->fakeCollection('talk', 3)['data']);
        $this->assertSame([], $this->fakeCollection('talk', 0)['data']);
    }

    public function test_an_alias_answers_what_the_client_resolves(): void
    {
        $this->use('conference');
        $source = $this->descriptor->source('default');
        Http::fake(['*' => Http::response($this->fakeAlias('track', 'uuid-1'))]);

        $path = (new DrupalClient($source->baseUrl, $source->jsonapiPath, retries: 0))->resolvePath('/track/africa');

        $this->assertSame('taxonomy_term/tracks/uuid-1', $path->endpoint);
        $this->assertSame('taxonomy_term--tracks', $path->resourceName);
        $this->assertSame('tracks', $path->bundle);
    }
}
