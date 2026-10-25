<?php

namespace Proteo\DrupalBackend\Search;

/**
 * A typed search term reduced to what may be sent upstream and highlighted.
 */
final readonly class SanitisedTerm
{
    /**
     * @param  string  $fulltext  Value for the upstream `filter[fulltext]`; empty when there is no usable term.
     * @param  list<string>  $terms  Words and quoted phrases (without quotes), as consumed by the Highlighter.
     */
    public function __construct(
        public string $fulltext = '',
        public array $terms = [],
    ) {}

    public function isUsable(): bool
    {
        return $this->fulltext !== '';
    }
}
