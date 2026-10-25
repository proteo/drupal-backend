<?php

namespace Proteo\DrupalBackend\Content\Related;

/**
 * One thing two entries can share: a relation of the source's content type.
 */
final readonly class Criterion
{
    /**
     * @param  string  $relation  Relation name on the source's content type.
     * @param  array<string, string>  $on  Target content type key to the relation that holds the same kind of entry there; the same name when absent.
     */
    public function __construct(
        public string $relation,
        public array $on = [],
    ) {}

    public function relationFor(string $contentType): string
    {
        return $this->on[$contentType] ?? $this->relation;
    }
}
