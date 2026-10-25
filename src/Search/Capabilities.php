<?php

namespace Proteo\DrupalBackend\Search;

/**
 * What a search backend can do, for the validator and the contract.
 * Views read each count's own `exact` flag instead.
 */
final readonly class Capabilities
{
    public function __construct(
        public bool $exactCounts = false,
        public bool $indexOnlyBooleans = false,
        public bool $fulltext = false,
    ) {}
}
