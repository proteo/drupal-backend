<?php

namespace Proteo\DrupalBackend\Search;

/**
 * One entry of the search field's suggestion list.
 */
final readonly class Suggestion
{
    /**
     * @param  string  $type  Content type key.
     * @param  string  $subtitle  Name of the related entry the type declares (e.g. a talk's speaker), or empty.
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public string $subtitle = '',
    ) {}
}
