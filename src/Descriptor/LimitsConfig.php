<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * Requests per minute and client for the named rate limiters.
 */
final readonly class LimitsConfig
{
    public function __construct(
        public int $content = 120,
        public int $searchSuggest = 60,
        public int $drupalWebhook = 60,
    ) {}
}
