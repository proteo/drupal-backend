<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * Names of fields or relations that feed the page metadata.
 */
final readonly class SeoConfig
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public int $descriptionMax = 160,
        public ?string $image = null,
        public ?string $author = null,
    ) {}
}
