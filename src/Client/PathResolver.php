<?php

namespace Proteo\DrupalBackend\Client;

use Proteo\DrupalBackend\Cache\CachedDrupal;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Resolves an alias through the caching layer and checks the resource kind.
 */
final readonly class PathResolver
{
    public function __construct(private CachedDrupal $drupal) {}

    /**
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function resolve(string $alias, string $resourceName, string $bundle): ResolvedPath
    {
        return $this->drupal->resolvePath($alias)->ensure($resourceName, $bundle);
    }
}
