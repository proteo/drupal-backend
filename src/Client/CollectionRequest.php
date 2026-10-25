<?php

namespace Proteo\DrupalBackend\Client;

/**
 * One collection request, as handed to the pooled and cached search methods.
 */
final readonly class CollectionRequest
{
    /**
     * @param  array<string, mixed>  $query
     */
    public function __construct(
        public string $resource,
        public array $query,
        public int $limit,
        public int $offset = 0,
    ) {}
}
