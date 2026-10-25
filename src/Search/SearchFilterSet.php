<?php

namespace Proteo\DrupalBackend\Search;

use Illuminate\Support\Str;
use LogicException;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\Filter;
use Proteo\DrupalBackend\Descriptor\FilterType;

/**
 * The filters of every searchable type; a filter applies on the tab of the type that declares it. Each filter type owns its
 * validation, its default and where its choices come from; invalid values
 * are dropped and reported, never sent upstream.
 */
final readonly class SearchFilterSet
{
    public function __construct(private Descriptor $descriptor) {}

    /**
     * Filters by key; keys are unique across types.
     *
     * @return array<string, Filter>
     */
    public function filters(): array
    {
        return array_replace([], ...array_values(array_map(fn (ContentType $type): array => $type->filters, $this->descriptor->searchableTypes())));
    }

    /**
     * Every URL parameter a tab's filters read.
     *
     * @return list<string>
     */
    public function parametersOf(string $tab): array
    {
        return array_merge([], ...array_values(array_map(fn (Filter $filter): array => $filter->parameters(), $this->descriptor->contentType($tab)->filters)));
    }

    /**
     * The tabs whose filters are in use, in descriptor order.
     *
     * @return list<string>
     */
    public function owners(SearchFilters $active): array
    {
        $used = array_keys(array_filter($active->values, fn (string|int|bool $value): bool => $value !== '' && $value !== false));
        $owners = [];

        foreach ($this->descriptor->searchableTypes() as $type) {
            foreach ($type->filters as $filter) {
                if (array_intersect($filter->parameters(), $used) !== []) {
                    $owners[] = $type->key;

                    break;
                }
            }
        }

        return $owners;
    }

    /**
     * Every URL parameter the filters read.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        return array_merge([], ...array_values(array_map(fn (Filter $filter): array => $filter->parameters(), $this->filters())));
    }

    /**
     * Empty input per parameter, so every parameter exists as a property path.
     *
     * @return array<string, string|bool>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->filters() as $filter) {
            foreach ($filter->parameters() as $parameter) {
                $defaults[$parameter] = $filter->type === FilterType::Boolean ? false : '';
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $input  Raw values by URL parameter.
     */
    public function validate(array $input): SearchFilters
    {
        $values = [];
        $problems = [];

        foreach ($this->filters() as $filter) {
            match ($filter->type) {
                FilterType::Equals => $this->equals($filter, $input, $values, $problems),
                FilterType::YearRange => $this->year($filter, $input, $values, $problems),
                FilterType::Boolean => $this->booleans($filter, $input, $values),
            };
        }

        return new SearchFilters($values, array_values(array_unique($problems)));
    }

    /**
     * Years offered by a `year_range` filter, newest first.
     *
     * @return list<int>
     */
    public function years(Filter $filter): array
    {
        return range(now()->year, $filter->firstYear ?? now()->year);
    }

    /**
     * The content type whose entries an `equals` filter offers as choices.
     */
    public function optionsOf(Filter $filter): string
    {
        $owner = array_find($this->descriptor->searchableTypes(), fn (ContentType $type): bool => ($type->filters[$filter->key] ?? null) === $filter);
        $relation = $owner?->relations[(string) $filter->relation] ?? null;

        return $relation?->to ?? throw new LogicException("Filter [{$filter->key}] has no content relation to offer choices from.");
    }

    public function label(Filter $filter): string
    {
        return $filter->label ?? Str::headline($filter->key);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string|int|bool>  $values
     * @param  list<string>  $problems
     */
    private function equals(Filter $filter, array $input, array &$values, array &$problems): void
    {
        $value = $this->text($input[$filter->key] ?? '');

        if ($value === '') {
            return;
        }

        if (Str::isUuid($value)) {
            $values[$filter->key] = $value;
        } else {
            $problems[] = "Ignored a {$filter->key} filter that is not valid.";
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string|int|bool>  $values
     * @param  list<string>  $problems
     */
    private function year(Filter $filter, array $input, array &$values, array &$problems): void
    {
        $value = $this->text($input[$filter->key] ?? '');

        if ($value === '') {
            return;
        }

        $year = filter_var($value, FILTER_VALIDATE_INT);

        if ($year !== false && $year >= ($filter->firstYear ?? PHP_INT_MIN) && $year <= now()->year) {
            $values[$filter->key] = $year;
        } else {
            $problems[] = "Ignored a {$filter->key} that is not valid.";
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string|int|bool>  $values
     */
    private function booleans(Filter $filter, array $input, array &$values): void
    {
        foreach ($filter->parameters() as $parameter) {
            if (filter_var($input[$parameter] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $values[$parameter] = true;
            }
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
