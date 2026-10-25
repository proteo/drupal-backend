<?php

namespace Proteo\DrupalBackend\Descriptor;

final readonly class Filter
{
    /**
     * @param  ?string  $relation  `equals`: the relation the UUID is compared against.
     * @param  ?string  $nativePath  `equals` and `year_range`: filter path on the native collection.
     * @param  array<string, BooleanField>  $fields  `boolean`: URL parameter to the field it selects on.
     * @param  BooleanMatch  $match  `boolean`: whether any or all of the checked fields must be true.
     * @param  ?string  $label  Heading of the control; defaults to the key.
     * @param  array<string, string>  $labels  `boolean`: label of each checkbox, by URL parameter.
     */
    public function __construct(
        public string $key,
        public FilterType $type,
        public ?string $relation = null,
        public ?string $nativePath = null,
        public ?int $firstYear = null,
        public array $fields = [],
        public BooleanMatch $match = BooleanMatch::Any,
        public ?string $label = null,
        public array $labels = [],
    ) {}

    /**
     * Every URL parameter this filter reads.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        return $this->type === FilterType::Boolean ? array_keys($this->fields) : [$this->key];
    }
}
