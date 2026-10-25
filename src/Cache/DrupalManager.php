<?php

namespace Proteo\DrupalBackend\Cache;

use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\Source;

/**
 * Hands out the cached client of each Source the descriptor declares, and
 * owns flushing, so a Source's dependents are never forgotten.
 */
class DrupalManager
{
    /** @var array<string, CachedDrupal> */
    private array $resolved = [];

    public function __construct(private readonly Descriptor $descriptor) {}

    /**
     * @param  ?string  $name  The default Source when omitted.
     */
    public function source(?string $name = null): CachedDrupal
    {
        $name ??= $this->descriptor->defaultSource;

        return $this->resolved[$name] ??= new CachedDrupal($this->client($this->descriptor->source($name)), $name, $this->descriptor->search->cacheSeconds);
    }

    public function client(Source $source): DrupalClient
    {
        // A Source's own overrides win over the shared defaults.
        $setting = fn (string $key): int => (int) ($source->overrides[$key] ?? config('drupal.'.$key));

        return new DrupalClient(
            baseUrl: $source->baseUrl,
            jsonapiPath: $source->jsonapiPath,
            timeout: $setting('timeout'),
            connectTimeout: $setting('connect_timeout'),
            retries: $setting('retries'),
            retryDelayMs: $setting('retry_delay_ms'),
        );
    }

    /**
     * Flushes a Source and, transitively, the Sources its `flush.also.sources` names.
     *
     * @return list<string> The Sources flushed.
     */
    public function flush(string $name): array
    {
        $pending = [$name];
        $flushed = [];

        while ($pending !== []) {
            $current = array_shift($pending);

            if (in_array($current, $flushed, true)) {
                continue;
            }

            $this->source($current)->flush();
            $flushed[] = $current;
            array_push($pending, ...$this->descriptor->source($current)->flushAlsoSources);
        }

        return $flushed;
    }

    /**
     * @return list<string> The Sources flushed.
     */
    public function flushAll(): array
    {
        foreach (array_keys($this->descriptor->sources) as $name) {
            $this->source($name)->flush();
        }

        return array_keys($this->descriptor->sources);
    }
}
