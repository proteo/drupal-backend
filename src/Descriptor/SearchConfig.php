<?php

namespace Proteo\DrupalBackend\Descriptor;

final readonly class SearchConfig
{
    /**
     * @param  list<string>  $tabs  Content type keys in display order.
     * @param  array<string, int>  $suggestionQuotas  Suggestions per content type.
     */
    public function __construct(
        public bool $enabled = false,
        public array $tabs = [],
        public int $perPage = 18,
        public int $nativeLimit = 50,
        public int $cacheSeconds = 60,
        public int $suggestionLimit = 10,
        public array $suggestionQuotas = [],
        public int $termMin = 2,
        public int $termMax = 100,
        public int $termMaxWords = 10,
    ) {}
}
