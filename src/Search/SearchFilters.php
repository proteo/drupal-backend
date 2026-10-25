<?php

namespace Proteo\DrupalBackend\Search;

/**
 * The validated filters of a search, keyed by the URL parameter that carried them.
 * An `equals` filter holds a UUID, a `year_range` a year and each checked `boolean` parameter `true`.
 */
final readonly class SearchFilters
{
    /**
     * @param  array<string, string|int|bool>  $values
     * @param  list<string>  $problems  Short messages about values that were dropped.
     */
    public function __construct(
        public array $values = [],
        public array $problems = [],
    ) {}

    public function value(string $parameter): string|int|bool|null
    {
        return $this->values[$parameter] ?? null;
    }

    /**
     * Number of filter inputs in use.
     */
    public function count(): int
    {
        return count(array_filter($this->values, fn (string|int|bool $value): bool => $value !== '' && $value !== false));
    }

    public function isActive(): bool
    {
        return $this->count() > 0;
    }
}
