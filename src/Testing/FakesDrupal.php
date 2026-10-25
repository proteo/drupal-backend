<?php

namespace Proteo\DrupalBackend\Testing;

use InvalidArgumentException;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\EntityDecorator;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\FieldType;
use Proteo\DrupalBackend\Descriptor\Relation;
use Proteo\DrupalBackend\Descriptor\RelationKind;

/**
 * Builds the JSON:API documents Drupal would answer for the project's own
 * descriptor, to feed `Http::fake()`. Every field gets a sample value and
 * every relation one related resource unless the test says otherwise, so a
 * generated entity always maps cleanly.
 *
 * Entities are returned as `[resource, included]` pairs, the shape the
 * collection builders accept.
 */
trait FakesDrupal
{
    private int $fakeSequence = 0;

    protected function drupalDescriptor(): Descriptor
    {
        return app(Descriptor::class);
    }

    /**
     * @param  array<string, mixed>  $values  Field values by frontend name, plus `id`, `path` and `count`.
     * @param  array<string, mixed>  $related  Relation name to its resources. A content relation takes a list of
     *                                         fakeEntity() pairs, a link relation a list of URLs, an image relation
     *                                         true, null or an array (`id`, `uri`, `alt`, `width`, `height`,
     *                                         `caption`, `styles`), a file relation true, null or an array
     *                                         (`id`, `uri`, `name`, `mime`, `size`). Empty values drop the relation.
     * @param  array<string, mixed>  $attributes  Raw JSON:API attributes, applied last.
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function fakeEntity(string $contentType, array $values = [], array $related = [], array $attributes = []): array
    {
        return $this->buildFakeEntity($this->drupalDescriptor()->contentType($contentType), $values, $related, $attributes, true);
    }

    /**
     * Binds a stand-in for the type's decorator, so a test controls the extras.
     *
     * @param  callable(list<Entity>): array<string, array<string, mixed>>  $extras  By entity id.
     */
    protected function fakeExtras(string $contentType, callable $extras): void
    {
        $class = $this->drupalDescriptor()->contentType($contentType)->decorator
            ?? throw new InvalidArgumentException("Content type [$contentType] declares no decorator.");

        $this->app->bind($class, fn (): EntityDecorator => new class($extras(...)) implements EntityDecorator
        {
            public function __construct(private readonly \Closure $extras) {}

            public function decorate(array $entities): array
            {
                return ($this->extras)($entities);
            }
        });
    }

    /**
     * A single-resource document, as the item endpoint answers.
     *
     * @param  array{0: array<string, mixed>, 1: list<array<string, mixed>>}  $entity
     * @return array<string, mixed>
     */
    protected function fakeItem(array $entity): array
    {
        return ['data' => $entity[0], 'included' => $entity[1]];
    }

    /**
     * A native collection document: no total, a `next` link when there are more pages.
     *
     * @param  int|list<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>  $items  A count to generate, or entities.
     * @return array<string, mixed>
     */
    protected function fakeCollection(string $contentType, int|array $items = 1, bool $hasMore = false): array
    {
        return $this->fakeDocument($this->fakeEntities($contentType, $items), $this->drupalDescriptor()->contentType($contentType), null, $hasMore);
    }

    /**
     * What Decoupled Router answers when an alias resolves to an entity.
     *
     * @return array<string, mixed>
     */
    protected function fakeAlias(string $contentType, string $uuid): array
    {
        $type = $this->drupalDescriptor()->contentType($contentType);
        $source = $this->drupalDescriptor()->source($type->source);

        return [
            'resolved' => $source->baseUrl.'/'.$type->key,
            'entity' => ['type' => $type->entityType, 'bundle' => $type->bundle, 'id' => $uuid],
            'jsonapi' => [
                'individual' => $source->baseUrl.$source->jsonapiPath.'/'.$type->resourcePath().'/'.$uuid,
                'resourceName' => $type->resourceType(),
            ],
        ];
    }

    /**
     * @param  int|list<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>  $items
     * @return list<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>
     */
    protected function fakeEntities(string $contentType, int|array $items): array
    {
        return is_int($items)
            ? array_map(fn (): array => $this->fakeEntity($contentType), $items > 0 ? range(1, $items) : [])
            : array_values($items);
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>  $entities
     * @return array<string, mixed>
     */
    protected function fakeDocument(array $entities, ContentType $type, ?int $total, bool $hasMore): array
    {
        $included = [];
        foreach ($entities as [, $resources]) {
            foreach ($resources as $resource) {
                $included[$resource['type'].':'.$resource['id']] = $resource;
            }
        }

        return [
            'data' => array_column($entities, 0),
            'included' => array_values($included),
            'meta' => $total === null ? [] : ['count' => $total],
            'links' => $hasMore ? ['next' => ['href' => $this->drupalDescriptor()->source($type->source)->baseUrl.'/next']] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $related
     * @param  array<string, mixed>  $attributes
     * @param  bool  $withRelations  Whether relations not named in `$related` get a default resource.
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function buildFakeEntity(ContentType $type, array $values, array $related, array $attributes, bool $withRelations): array
    {
        $n = ++$this->fakeSequence;

        foreach (array_diff(array_keys($values), ['id', 'path'], array_keys($type->fields)) as $unknown) {
            throw new InvalidArgumentException("Content type [{$type->key}] has no field [$unknown].");
        }
        foreach (array_diff(array_keys($related), array_keys($type->relations)) as $unknown) {
            throw new InvalidArgumentException("Content type [{$type->key}] has no relation [$unknown].");
        }

        $slug = $type->key.'-'.$n;
        if ($type->hasAlias()) {
            $attributes += ['path' => $values['path'] ?? '/'.trim($type->aliasPrefix.'/'.$slug, '/')];
        }

        foreach ($type->fields as $name => $field) {
            // An explicit null leaves the attribute empty instead of getting a sample.
            data_set($attributes, $field->source, array_key_exists($name, $values) ? $values[$name] : $this->sampleValue($name, $field->type, $n), false);
        }

        $resource = [
            'type' => $type->resourceType(),
            'id' => $values['id'] ?? $slug,
            'attributes' => $attributes,
            'relationships' => [],
        ];
        $included = [];

        foreach ($type->relations as $name => $relation) {
            $spec = array_key_exists($name, $related) ? $related[$name] : ($withRelations ? $this->defaultRelated($relation) : null);
            [$linkage, $resources] = $this->relationship($relation, $spec, $n);

            $resource['relationships'][$relation->via] = ['data' => $linkage];
            array_push($included, ...$resources);
        }

        return [$resource, $included];
    }

    private function sampleValue(string $name, FieldType $type, int $n): mixed
    {
        $text = ucfirst(str_replace('_', ' ', $name)).' '.$n;

        return match ($type) {
            FieldType::Html, FieldType::Excerpt => "<p>$text</p>",
            FieldType::Date => '2024-01-01T00:00:00+00:00',
            FieldType::Integer => $n,
            FieldType::Boolean => true,
            FieldType::String => $text,
        };
    }

    private function defaultRelated(Relation $relation): mixed
    {
        return match ($relation->kind) {
            RelationKind::Content => [$this->buildFakeEntity($this->drupalDescriptor()->contentType((string) $relation->to), [], [], [], false)],
            RelationKind::Image, RelationKind::File => true,
        };
    }

    /**
     * @return array{0: mixed, 1: list<array<string, mixed>>} The relationship linkage and the resources to include.
     */
    private function relationship(Relation $relation, mixed $spec, int $n): array
    {
        if ($relation->kind === RelationKind::Image) {
            return $this->imageRelationship($relation, $spec, $n);
        }

        if ($relation->kind === RelationKind::File) {
            return $this->fileRelationship($relation, $spec, $n);
        }

        $resources = [];
        $identifiers = [];

        foreach ((array) ($spec ?: []) as $item) {
            [$resource, $nested] = $item;
            array_push($resources, $resource, ...$nested);

            $identifiers[] = ['type' => $resource['type'], 'id' => $resource['id']];
        }

        return [$relation->many ? $identifiers : ($identifiers[0] ?? null), $resources];
    }

    /**
     * The media and file resources behind an image relation; alt text and size travel in the linkage meta.
     *
     * @return array{0: mixed, 1: list<array<string, mixed>>}
     */
    private function imageRelationship(Relation $relation, mixed $spec, int $n): array
    {
        if (! $spec) {
            return [null, []];
        }

        $image = is_array($spec) ? $spec : [];
        $mediaId = $image['id'] ?? "media-$n-{$relation->name}";
        $fileId = 'file-'.$mediaId;
        $field = $relation->imageField ?? 'field_media_image';
        // An explicit null leaves that piece of meta out.
        $meta = array_filter(
            array_intersect_key($image, array_flip(['alt', 'width', 'height'])) + ['alt' => 'Image '.$n, 'width' => 800, 'height' => 600],
            fn (mixed $value): bool => $value !== null,
        );

        $media = [
            'type' => 'media--image',
            'id' => $mediaId,
            'attributes' => isset($image['caption']) ? ['caption' => $image['caption']] : [],
            'relationships' => [$field => ['data' => ['type' => 'file--file', 'id' => $fileId, 'meta' => $meta]]],
        ];
        $file = [
            'type' => 'file--file',
            'id' => $fileId,
            'attributes' => ['uri' => $image['uri'] ?? "/files/$mediaId.jpg", 'image_style_uri' => $image['styles'] ?? []],
        ];

        return [['type' => 'media--image', 'id' => $mediaId], [$media, $file]];
    }

    /**
     * The file resource behind a file relation.
     *
     * @return array{0: mixed, 1: list<array<string, mixed>>}
     */
    private function fileRelationship(Relation $relation, mixed $spec, int $n): array
    {
        if (! $spec) {
            return [null, []];
        }

        $file = is_array($spec) ? $spec : [];
        $id = $file['id'] ?? "file-$n-{$relation->name}";
        $resource = [
            'type' => 'file--file',
            'id' => $id,
            'attributes' => [
                'uri' => $file['uri'] ?? "/files/$id.pdf",
                'filename' => $file['name'] ?? "$id.pdf",
                'filemime' => $file['mime'] ?? 'application/pdf',
                'filesize' => $file['size'] ?? 1024,
            ],
        ];

        return [['type' => 'file--file', 'id' => $id], [$resource]];
    }
}
