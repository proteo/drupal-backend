<?php

namespace Proteo\DrupalBackend\Content;

use LogicException;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\DescriptorValidator;

/**
 * The immutable object a view receives for one content item.
 *
 * Fields and resolved relations are read by their frontend names, as
 * properties (`$talk->title`) or through `get()`. An unknown name throws,
 * never renders empty. Every entity also has `id`, `type`, `path` and
 * `url()`. A decorator's extras are read with `extra()`. A project may extend this class per content type
 * (named in the descriptor) to add presentation helpers.
 */
readonly class Entity
{
    public string $type;

    /**
     * @param  array<string, mixed>  $values  Field and relation values by frontend name.
     */
    public function __construct(
        protected ContentType $contentType,
        public string $id,
        public ?string $path,
        protected array $values = [],
        private Extras $extras = new Extras,
    ) {
        $this->type = $contentType->key;

        if ($clash = array_intersect(DescriptorValidator::BUILT_IN_NAMES, array_keys($values))) {
            throw new LogicException('Entity values cannot use the built-in names ['.implode(', ', $clash).'].');
        }
    }

    /**
     * @throws UnknownAttribute
     */
    public function get(string $name): mixed
    {
        if (in_array($name, ['id', 'type', 'path'], true)) {
            return $this->$name;
        }

        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        // Declared but absent from the payload, e.g. an optional field left empty.
        if ($this->declares($name)) {
            return null;
        }

        throw UnknownAttribute::named($name, $this->type);
    }

    public function has(string $name): bool
    {
        return $this->declares($name)
            || in_array($name, ['id', 'type', 'path'], true);
    }

    /**
     * A value the type's decorator added; null when it returned nothing for this entity.
     *
     * @throws LogicException When the type declares no decorator.
     */
    public function extra(string $name): mixed
    {
        $this->assertDecorated();

        return $this->extras->for($this->type, $this->id)[$name] ?? null;
    }

    /**
     * @throws LogicException When the type declares no decorator.
     */
    public function hasExtra(string $name): bool
    {
        $this->assertDecorated();

        return array_key_exists($name, $this->extras->for($this->type, $this->id));
    }

    /**
     * The public URL: the URL prefix plus the alias without its alias prefix.
     */
    public function url(): string
    {
        if ($this->path === null) {
            throw new LogicException("Content type [$this->type] has no path alias, so [$this->id] has no URL.");
        }

        return $this->contentType->url($this->path);
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Follows PHP's isset(): a known name holding null is not set, an unknown name is not an error.
     */
    public function __isset(string $name): bool
    {
        return $this->has($name) && $this->get($name) !== null;
    }

    public function __set(string $name, mixed $value): never
    {
        throw new LogicException('Entities are immutable.');
    }

    public function __unset(string $name): never
    {
        throw new LogicException('Entities are immutable.');
    }

    private function assertDecorated(): void
    {
        if ($this->contentType->decorator === null) {
            throw new LogicException("Content type [$this->type] declares no decorator, so it has no extras.");
        }
    }

    private function declares(string $name): bool
    {
        return isset($this->contentType->fields[$name]) || isset($this->contentType->relations[$name]);
    }
}
