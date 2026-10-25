<?php

namespace Proteo\DrupalBackend\Client;

/**
 * An immutable decoded JSON:API document.
 *
 * Built once per response; `included` resources are indexed by type and id and
 * relationships resolve on demand, however deeply they are nested.
 */
final readonly class JsonApiDocument
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $included;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $index = [];

        foreach ($payload['included'] ?? [] as $resource) {
            if (isset($resource['type'], $resource['id'])) {
                $index[$resource['type']][$resource['id']] = $resource;
            }
        }

        $this->included = $index;
    }

    /**
     * The primary data: one resource object or a list of them.
     *
     * @return array<int|string, mixed>
     */
    public function data(): array
    {
        return $this->payload['data'];
    }

    /**
     * The included resource a relationship points to, in document order.
     * Linkage to resources that were not included is skipped.
     *
     * @param  array<string, mixed>  $resource
     * @return list<array<string, mixed>>
     */
    public function related(array $resource, string $relationship): array
    {
        $related = [];

        foreach ($this->linkage($resource, $relationship) as $identifier) {
            $found = $this->included[$identifier['type'] ?? ''][$identifier['id'] ?? ''] ?? null;

            if ($found !== null) {
                $related[] = $found;
            }
        }

        return $related;
    }

    /**
     * The `meta` of the first resource identifier of a relationship; Drupal
     * keeps an image's alt text and dimensions there.
     *
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function relationshipMeta(array $resource, string $relationship): array
    {
        return $this->linkage($resource, $relationship)[0]['meta'] ?? [];
    }

    /**
     * Resource identifiers of a to-one or to-many relationship.
     *
     * @param  array<string, mixed>  $resource
     * @return list<array<string, mixed>>
     */
    private function linkage(array $resource, string $relationship): array
    {
        $data = $resource['relationships'][$relationship]['data'] ?? null;

        if (! is_array($data) || $data === []) {
            return [];
        }

        // A to-one linkage is a single identifier object, a to-many a list.
        return isset($data['id']) ? [$data] : array_values(array_filter($data, 'is_array'));
    }
}
