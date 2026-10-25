<?php

namespace Proteo\DrupalBackend\Client;

/**
 * A decoded JSON:API document and whether the backend has further results.
 */
final readonly class DrupalResponse
{
    public JsonApiDocument $document;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(array $payload, public bool $hasMore = false)
    {
        $this->document = new JsonApiDocument($payload);
    }

    /**
     * The index total (`meta.count`) when the backend reports one, else the
     * number of items returned.
     */
    public function total(): int
    {
        $count = $this->document->payload['meta']['count'] ?? null;

        return is_int($count) ? $count : count($this->data());
    }

    /**
     * @return array<int|string, mixed>
     */
    public function data(): array
    {
        return $this->document->data();
    }
}
