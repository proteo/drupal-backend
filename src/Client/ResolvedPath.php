<?php

namespace Proteo\DrupalBackend\Client;

use Proteo\DrupalBackend\Exceptions\DrupalNotFound;

/**
 * What Drupal's path translation says an alias points to.
 */
final readonly class ResolvedPath
{
    /**
     * @param  string  $endpoint  Path under the JSON:API root, e.g. "node/talk/<uuid>".
     * @param  string  $resourceName  JSON:API resource name, e.g. "node--talk".
     * @param  string  $bundle  Entity bundle, e.g. "talk".
     */
    public function __construct(
        public string $endpoint,
        public string $resourceName,
        public string $bundle,
    ) {}

    /**
     * Resource type path, e.g. "node/talk".
     */
    public function resource(): string
    {
        return dirname($this->endpoint);
    }

    public function uuid(): string
    {
        return basename($this->endpoint);
    }

    /**
     * Aliases resolve under any route, so callers confirm the kind of resource they expect.
     *
     * @throws DrupalNotFound
     */
    public function ensure(string $resourceName, string $bundle): self
    {
        if ($this->resourceName !== $resourceName || $this->bundle !== $bundle) {
            throw new DrupalNotFound(['reason' => 'wrong resource type', 'resource' => $this->resourceName]);
        }

        return $this;
    }
}
