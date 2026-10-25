<?php

namespace Proteo\DrupalBackend\Search;

/**
 * Decides how one tab of a search is answered. The fetcher stays concrete:
 * it sends every tab's requests as one concurrent batch and hands each
 * plan its responses, so a backend never fetches the first round itself.
 *
 * A backend must let any `DrupalException` propagate; a failed request
 * fails the whole search.
 */
interface SearchBackend
{
    public function plan(TabSearch $search): TabPlan;

    public function capabilities(): Capabilities;
}
