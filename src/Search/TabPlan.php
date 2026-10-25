<?php

namespace Proteo\DrupalBackend\Search;

use Closure;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Client\CollectionRequest;
use Proteo\DrupalBackend\Client\DrupalResponse;

/**
 * The requests one tab contributes to the shared batch, and how to read their responses.
 */
final readonly class TabPlan
{
    /**
     * @param  list<CollectionRequest>  $requests
     * @param  Closure(list<DrupalResponse>, CachedDrupal): TabResult  $resolver
     */
    public function __construct(
        public array $requests,
        private Closure $resolver,
    ) {}

    /**
     * May send follow-up requests through the Source client; those of
     * different tabs run one after another.
     *
     * @param  list<DrupalResponse>  $responses  One per request of this plan, in order.
     */
    public function resolve(array $responses, CachedDrupal $drupal): TabResult
    {
        return ($this->resolver)($responses, $drupal);
    }
}
