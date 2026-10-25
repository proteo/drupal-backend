<?php

namespace Proteo\DrupalBackend\Content\Related;

use Proteo\DrupalBackend\Content\Entity;

/**
 * What to relate to an entity. Criteria are in priority order: sharing more
 * entries of the first outranks any number of the next.
 */
final readonly class RelatedRequest
{
    /** @var non-empty-list<string> */
    public array $types;

    /**
     * @param  list<Criterion>  $criteria
     * @param  list<string>  $types  Content type keys to look in; the source's own type when empty.
     * @param  ?string  $order  Attribute that breaks ties, `-` for descending (`-created`); must exist on every target type.
     * @param  array<string, mixed>  $extras  Hints for a strategy; one that does not know a hint ignores it.
     */
    public function __construct(
        public Entity $source,
        public array $criteria,
        array $types = [],
        public int $limit = 10,
        public ?string $order = null,
        public array $extras = [],
    ) {
        $this->types = $types === [] ? [$source->type] : array_values($types);
    }
}
