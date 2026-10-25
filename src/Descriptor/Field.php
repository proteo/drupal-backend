<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * A frontend-named property sourced from a Drupal field path.
 */
final readonly class Field
{
    public function __construct(
        public string $name,
        public string $source,
        public FieldType $type = FieldType::String,
        public bool $required = false,
        public ?int $length = null,
    ) {}
}
