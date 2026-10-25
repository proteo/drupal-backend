<?php

namespace Proteo\DrupalBackend\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Proteo\DrupalBackend\Client\CollectionRequest;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Client\DrupalResponse;
use Proteo\DrupalBackend\Client\ResolvedPath;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Caches the client's decoded JSON:API documents; models are never cached.
 *
 * Every key embeds the content generation, so bumping it orphans all entries
 * without needing a purge query or tag support from the store.
 */
final class CachedDrupal
{
    private const string GENERATION_KEY = 'drupal:generation';

    /**
     * @param  string  $source  Name of the Source this cache serves; each has its own generation.
     * @param  int  $searchWindow  Seconds a search result is served without refetching; 0 disables search caching.
     */
    public function __construct(private readonly DrupalClient $client, private readonly string $source = 'default', private readonly int $searchWindow = 60) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws \Exception
     * @throws InvalidArgumentException
     */
    public function collection(string $resource, array $query, int $limit, int $offset = 0): DrupalResponse
    {
        $payload = $this->remember(
            ['collection', $resource, $query, $limit, $offset],
            function () use ($resource, $query, $limit, $offset): array {
                $response = $this->client->collection($resource, $query, $limit, $offset);

                return ['document' => $response->document->payload, 'hasMore' => $response->hasMore];
            },
        );

        return $this->response($payload);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws \Exception
     * @throws InvalidArgumentException
     */
    public function item(string $resource, string $uuid, array $query = []): DrupalResponse
    {
        $payload = $this->remember(
            ['item', $resource, $uuid, $query],
            fn (): array => ['document' => $this->client->item($resource, $uuid, $query)->document->payload, 'hasMore' => false],
        );

        return $this->response($payload);
    }

    /**
     * Fetches several collections concurrently with a short fresh-only cache.
     *
     * Unlike the content cache there is no stale window and no stale-if-error:
     * an entry past the search window is refetched, and errors are never cached.
     *
     * @param  list<CollectionRequest>  $requests
     * @return list<DrupalResponse>
     *
     * @throws \JsonException
     * @throws InvalidArgumentException
     */
    public function search(array $requests): array
    {
        $requests = array_values($requests);
        $window = $this->searchWindow;

        if (! $this->enabled() || $window <= 0) {
            return $this->client->pool($requests);
        }

        $keys = array_map(
            fn (CollectionRequest $r): string => $this->key(['search', $r->resource, $r->query, $r->limit, $r->offset]),
            $requests,
        );

        $payloads = $this->store()->many($keys);
        $missing = array_keys(array_filter($payloads, fn (mixed $payload): bool => $payload === null));

        if ($missing !== []) {
            $fetched = $this->client->pool(array_map(fn (string $key): CollectionRequest => $requests[array_search($key, $keys, true)], $missing));

            foreach ($missing as $position => $key) {
                $payloads[$key] = ['document' => $fetched[$position]->document->payload, 'hasMore' => $fetched[$position]->hasMore];
                $this->store()->put($key, $payloads[$key], $window);
            }
        }

        return array_map(fn (string $key): DrupalResponse => $this->response($payloads[$key]), $keys);
    }

    private function response(array $payload): DrupalResponse
    {
        return new DrupalResponse($payload['document'], $payload['hasMore']);
    }

    /**
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws \Exception
     */
    public function resolvePath(string $alias): ResolvedPath
    {
        if (! $this->enabled()) {
            return $this->client->resolvePath($alias);
        }

        $negativeKey = $this->key(['missing', $alias]);

        if ($this->store()->has($negativeKey)) {
            throw new DrupalNotFound(['reason' => 'cached miss']);
        }

        try {
            $cached = $this->remember(['path', $alias], function () use ($alias): array {
                $path = $this->client->resolvePath($alias);

                return [$path->endpoint, $path->resourceName, $path->bundle];
            });
        } catch (DrupalNotFound $e) {
            // Only definite misses are remembered; unavailable and bad responses propagate uncached.
            $this->store()->put($negativeKey, true, (int) config('drupal.cache.negative'));

            throw $e;
        }

        return new ResolvedPath(...$cached);
    }

    /**
     * Invalidates everything cached for this Source so far by moving to a new generation.
     */
    public function flush(): void
    {
        $this->store()->forever(self::generationKey($this->source), (string) Str::uuid());
    }

    public static function generationKey(string $source): string
    {
        return self::GENERATION_KEY.':'.$source;
    }

    /**
     * Serves fresh copies directly, stale ones while refreshing after the fresh
     * window, and keeps the stale copy when the refresh fails.
     *
     * @param  list<mixed>  $parts
     * @param  Closure(): array<string, mixed>  $load
     *
     * @throws \JsonException
     * @throws InvalidArgumentException
     */
    private function remember(array $parts, Closure $load): array
    {
        if (! $this->enabled()) {
            return $load();
        }

        return $this->store()->flexible(
            $this->key($parts),
            [(int) config('drupal.cache.fresh'), (int) config('drupal.cache.fresh') + (int) config('drupal.cache.stale')],
            $load,
        );
    }

    /**
     * @param  list<mixed>  $parts
     *
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    private function key(array $parts): string
    {
        $generation = $this->store()->get(self::generationKey($this->source), 'initial');

        return 'drupal:'.$this->source.':'.$generation.':'.sha1(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function enabled(): bool
    {
        return (bool) config('drupal.cache.enabled');
    }

    private function store(): Repository
    {
        return Cache::store(config('drupal.cache.store'));
    }
}
