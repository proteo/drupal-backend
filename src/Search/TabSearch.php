<?php

namespace Proteo\DrupalBackend\Search;

use Proteo\DrupalBackend\Descriptor\ContentType;

/**
 * One tab of a search: the content type and everything a backend needs to plan it.
 */
final readonly class TabSearch
{
    /**
     * @param  string  $sort  `relevance`, `title` or a name declared in the type's `sorts`.
     * @param  bool  $active  Only the shown tab needs includes, a sort and a listing; the others are fetched for their count.
     */
    public function __construct(
        public ContentType $type,
        public SanitisedTerm $term,
        public SearchFilters $filters,
        public string $sort,
        public int $offset,
        public bool $active,
    ) {}
}
