<?php

namespace Proteo\DrupalBackend\Content;

use Proteo\DrupalBackend\Descriptor\Descriptor;

/**
 * Builds entities, using the project's subclass when the descriptor names one.
 */
class EntityFactory
{
    public function __construct(private readonly Descriptor $descriptor) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function make(string $contentType, string $id, ?string $path, array $values = [], ?Extras $extras = null): Entity
    {
        $type = $this->descriptor->contentType($contentType);
        $class = $type->entity ?? Entity::class;

        return new $class($type, $id, $path, $values, $extras ?? new Extras);
    }
}
