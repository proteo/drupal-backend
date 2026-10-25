<?php

namespace Proteo\DrupalBackend\Descriptor;

use InvalidArgumentException;

/**
 * The validated description of a project's Drupal content model.
 */
final readonly class Descriptor
{
    public const int SCHEMA_VERSION = 3;

    /**
     * @param  array<string, Source>  $sources
     * @param  array<string, ContentType>  $contentTypes
     */
    public function __construct(
        public array $sources,
        public string $defaultSource,
        public array $contentTypes,
        public SearchConfig $search = new SearchConfig,
        public RoutesConfig $routes = new RoutesConfig,
        public WebhookConfig $webhook = new WebhookConfig,
        public LimitsConfig $limits = new LimitsConfig,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    public function contentType(string $key): ContentType
    {
        return $this->contentTypes[$key] ?? throw new InvalidArgumentException("Unknown content type [$key].");
    }

    public function source(string $name): Source
    {
        return $this->sources[$name] ?? throw new InvalidArgumentException("Unknown source [$name].");
    }

    /**
     * The content types that have a search tab, in descriptor order.
     *
     * @return array<string, ContentType>
     */
    public function searchableTypes(): array
    {
        return array_filter($this->contentTypes, fn (ContentType $type): bool => $type->searchable);
    }

    /**
     * @return array<string, ContentType>
     */
    public function ofRole(Role $role): array
    {
        return array_filter($this->contentTypes, fn (ContentType $type): bool => $type->role === $role);
    }
}
