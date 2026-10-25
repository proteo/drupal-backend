<?php

namespace Proteo\DrupalBackend\Content;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\Field;
use Proteo\DrupalBackend\Descriptor\FieldType;
use Proteo\DrupalBackend\Descriptor\Relation;
use Proteo\DrupalBackend\Descriptor\RelationKind;
use Proteo\DrupalBackend\Descriptor\Role;
use Proteo\DrupalBackend\Descriptor\Source;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Search\PlainText;
use WeakMap;

/**
 * Turns JSON:API resources into entities as the descriptor declares them.
 *
 * A required field, or the id or path, missing means the upstream data is
 * broken and raises a bad-response failure for the whole page. A broken
 * optional relation is dropped and logged. Text and image preparation
 * happens here, once, using the entity's own Source.
 *
 * Every entity mapped from one document shares that document's Extras; the
 * caller runs decorate() once the whole response is mapped.
 */
class ContentMapper
{
    /**
     * @var WeakMap<JsonApiDocument, Extras>
     */
    private WeakMap $extras;

    /**
     * @param  string  $appUrl  Origin of this site; a Source's `frontend_url` takes precedence for its own content.
     */
    public function __construct(
        private readonly Descriptor $descriptor,
        private readonly EntityFactory $entities,
        private readonly string $appUrl,
        private readonly ?Container $container = null,
    ) {
        $this->extras = new WeakMap;
    }

    /**
     * Calls each type's decorator once with the entities mapped from the
     * document so far, nested ones included. Exceptions propagate.
     */
    public function decorate(JsonApiDocument $document): void
    {
        $extras = $this->extrasOf($document);

        foreach ($extras->drain() as $type => $entities) {
            $class = $this->descriptor->contentType($type)->decorator;

            if ($class !== null) {
                $extras->put($type, ($this->container ?? Container::getInstance())->make($class)->decorate($entities));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     *
     * @throws DrupalBadResponse
     */
    public function map(string $contentType, array $resource, JsonApiDocument $document): Entity
    {
        return $this->build($this->descriptor->contentType($contentType), $resource, $document, []);
    }

    /**
     * Like map(), for content that may be missing: a Reference type, or any
     * type the caller treats as optional, becomes null instead of a failure.
     *
     * @param  array<string, mixed>  $resource
     */
    public function mapOrNull(string $contentType, array $resource, JsonApiDocument $document): ?Entity
    {
        $type = $this->descriptor->contentType($contentType);

        try {
            return $this->build($type, $resource, $document, []);
        } catch (DrupalBadResponse $exception) {
            if (! $this->isOptional($type)) {
                throw $exception;
            }

            $this->drop($type->key, $exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<string>  $trail  Resources being mapped above this one, which a cyclic relation must not revisit.
     */
    private function build(ContentType $type, array $resource, JsonApiDocument $document, array $trail): Entity
    {
        $source = $this->descriptor->source($type->source);
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
        $id = $this->nonEmpty($resource['id'] ?? null, 'id', $resource);
        $trail[] = ($resource['type'] ?? $type->resourceType()).':'.$id;

        $values = [];
        foreach ($type->fields as $name => $field) {
            $values[$name] = $this->field($field, $attributes, $resource, $source);
        }

        foreach ($type->relations as $name => $relation) {
            $values[$name] = $this->relation($relation, $resource, $document, $source, $trail);
        }

        $entity = $this->entities->make(
            $type->key,
            $id,
            // Only a type with an alias has a path; a paragraph or media entity has none.
            $type->hasAlias() ? $this->nonEmpty($attributes['path'] ?? null, 'path', $resource) : null,
            $values,
            $extras = $this->extrasOf($document),
        );

        if ($type->decorator !== null) {
            $extras->track($entity);
        }

        return $entity;
    }

    private function extrasOf(JsonApiDocument $document): Extras
    {
        return $this->extras[$document] ??= new Extras;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $resource
     */
    private function field(Field $field, array $attributes, array $resource, Source $source): mixed
    {
        $value = data_get($attributes, $field->source);

        if ($field->required) {
            return $this->nonEmpty($value, $field->name, $resource);
        }

        return match ($field->type) {
            FieldType::Html => $this->text($value, $source),
            FieldType::Excerpt => is_string($value) && $value !== '' ? PlainText::excerpt($value, $field->length ?? 200) : null,
            FieldType::Integer => is_numeric($value) ? (int) $value : null,
            FieldType::Boolean => is_bool($value) ? $value : null,
            FieldType::String, FieldType::Date => is_string($value) && $value !== '' ? $value : null,
        };
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<string>  $trail
     */
    private function relation(Relation $relation, array $resource, JsonApiDocument $document, Source $source, array $trail): mixed
    {
        if ($relation->kind === RelationKind::Image) {
            return $this->guarded($relation, fn () => $this->image($relation, $resource, $document, $source), $resource);
        }

        if ($relation->kind === RelationKind::File) {
            return $this->guarded($relation, fn () => $this->file($relation, $resource, $document, $source), $resource);
        }

        $items = [];
        foreach ($document->related($resource, $relation->via) as $related) {
            $item = $this->guarded($relation, fn () => $this->relatedItem($relation, $related, $document, $trail), $resource);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $relation->many ? $items : ($items[0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $related
     * @param  list<string>  $trail
     */
    private function relatedItem(Relation $relation, array $related, JsonApiDocument $document, array $trail): ?Entity
    {
        // A relation that loops back to a resource being mapped stops there.
        if (in_array(($related['type'] ?? '').':'.($related['id'] ?? ''), $trail, true)) {
            return null;
        }

        return $this->build($this->descriptor->contentType((string) $relation->to), $related, $document, $trail);
    }

    /**
     * Runs a relation lookup; a failure drops an optional relation and escapes a required one.
     *
     * @param  callable(): mixed  $resolve
     * @param  array<string, mixed>  $resource
     */
    private function guarded(Relation $relation, callable $resolve, array $resource): mixed
    {
        try {
            return $resolve();
        } catch (DrupalBadResponse $exception) {
            $target = $relation->to === null ? null : $this->descriptor->contentType($relation->to);

            if (! $relation->optional && ($target === null || ! $this->isOptional($target))) {
                throw $exception;
            }

            $this->drop(($resource['type'] ?? '').'.'.$relation->name, $exception);

            return null;
        }
    }

    /**
     * Resolves a media entity relationship to an Image, or null when absent.
     *
     * @param  array<string, mixed>  $resource
     */
    private function image(Relation $relation, array $resource, JsonApiDocument $document, Source $source): ?Image
    {
        $fileRelationship = $relation->imageField ?? 'field_media_image';
        $media = $document->related($resource, $relation->via)[0] ?? null;
        $file = $media !== null ? ($document->related($media, $fileRelationship)[0] ?? null) : null;

        if ($media === null || $file === null) {
            return null;
        }

        // Alt text and dimensions travel in the relationship's meta, not on the file.
        $meta = $document->relationshipMeta($media, $fileRelationship);
        $fileAttributes = is_array($file['attributes'] ?? null) ? $file['attributes'] : [];
        $caption = $media['attributes']['caption'] ?? null;

        $uri = $fileAttributes['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            return null;
        }

        $styles = is_array($fileAttributes['image_style_uri'] ?? null)
            ? array_filter($fileAttributes['image_style_uri'], fn (mixed $url): bool => is_string($url) && $url !== '')
            : [];

        return new Image(
            src: $source->baseUrl.$uri,
            alt: isset($meta['alt']) ? (string) $meta['alt'] : null,
            width: isset($meta['width']) ? (int) $meta['width'] : null,
            height: isset($meta['height']) ? (int) $meta['height'] : null,
            caption: is_string($caption) && $caption !== '' ? $this->text($caption, $source) : null,
            styles: $styles,
        );
    }

    /**
     * Resolves the file entity behind a relationship to a File, or null when absent.
     *
     * @param  array<string, mixed>  $resource
     */
    private function file(Relation $relation, array $resource, JsonApiDocument $document, Source $source): ?File
    {
        $attributes = $document->related($resource, $relation->via)[0]['attributes'] ?? null;
        $uri = is_array($attributes) ? ($attributes['uri'] ?? null) : null;

        if (! is_string($uri) || $uri === '') {
            return null;
        }

        return new File(
            url: $source->baseUrl.$uri,
            name: is_string($attributes['filename'] ?? null) && $attributes['filename'] !== '' ? $attributes['filename'] : null,
            mime: is_string($attributes['filemime'] ?? null) && $attributes['filemime'] !== '' ? $attributes['filemime'] : null,
            size: is_numeric($attributes['filesize'] ?? null) ? (int) $attributes['filesize'] : null,
        );
    }

    /**
     * Makes HTML from Drupal usable on this site: links to the backend point
     * at the frontend and relative file paths become absolute backend URLs.
     */
    private function text(mixed $html, Source $source): string
    {
        if (! is_string($html) || $html === '') {
            return '';
        }

        $html = html_entity_decode($html);
        $search = ['href="'.$source->baseUrl];
        $replace = ['href="'.($source->frontendUrl ?? $this->appUrl)];

        // An empty files path would match every attribute.
        if ($source->filesPath !== '') {
            $search[] = '="'.$source->filesPath;
            $replace[] = '="'.$source->baseUrl.$source->filesPath;
        }

        return str_replace($search, $replace, $html);
    }

    private function isOptional(ContentType $type): bool
    {
        return $type->optional || $type->role === Role::Reference;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function nonEmpty(mixed $value, string $field, array $resource): string
    {
        if (! is_string($value) || $value === '') {
            throw new DrupalBadResponse([
                'reason' => 'missing required field',
                'field' => $field,
                'resource' => $resource['type'] ?? null,
            ]);
        }

        return $value;
    }

    private function drop(string $what, DrupalBadResponse $exception): void
    {
        Log::warning('Dropped a broken optional Drupal relation.', ['target' => $what, 'detail' => $exception->context()]);
    }
}
