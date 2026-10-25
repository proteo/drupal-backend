<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * One Drupal backend connection.
 */
final readonly class Source
{
    /**
     * @param  list<string>  $flushAlsoSources  Sources whose cache a webhook for this one also flushes.
     * @param  array<string, mixed>  $overrides  Timeouts and retries (`timeout`, `connect_timeout`, `retries`, `retry_delay_ms`) that differ from the `drupal.*` defaults.
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $jsonapiPath = '/jsonapi',
        public string $filesPath = '',
        public ?string $frontendUrl = null,
        public ?string $webhookSecret = null,
        public ?int $webhookTolerance = null,
        public array $flushAlsoSources = [],
        public array $overrides = [],
    ) {}
}
