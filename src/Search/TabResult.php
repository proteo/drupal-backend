<?php

namespace Proteo\DrupalBackend\Search;

use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\Listing;

/**
 * A tab's count and, for the active tab, its page.
 */
final readonly class TabResult
{
    /**
     * @param  bool  $exact  False when the count is a floor, such as matches cut at a request cap.
     * @param  ?Listing<Entity>  $listing  Set for the active tab only.
     */
    public function __construct(
        public int $count,
        public bool $exact = true,
        public ?Listing $listing = null,
    ) {}
}
