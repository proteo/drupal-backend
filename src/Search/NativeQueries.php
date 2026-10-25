<?php

namespace Proteo\DrupalBackend\Search;

use DateTimeImmutable;
use DateTimeZone;
use Proteo\DrupalBackend\Client\CollectionRequest;
use Proteo\DrupalBackend\Descriptor\BooleanMatch;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Filter;
use Proteo\DrupalBackend\Descriptor\FilterType;
use Proteo\DrupalBackend\Descriptor\SearchConfig;

/**
 * Builds the requests of a search against a content type's native collection,
 * which matches partial words. Also the native halves of the filters
 * (`equals`, `year_range`, `boolean`) for backends that add their own.
 */
final readonly class NativeQueries
{
    public function __construct(private SearchConfig $config) {}

    /**
     * Every word must appear in one of the type's own term fields. With
     * `$relatedPath` the request matches that related path only: filtering on
     * a related name joins it, which silently drops items that have none even
     * when their own text matches, so it cannot share an OR group.
     */
    public function native(ContentType $type, SanitisedTerm $term, SearchFilters $filters, bool $active, ?string $sort, ?string $relatedPath = null, ?int $limit = null, int $offset = 0): CollectionRequest
    {
        $query = $type->conditionQuery();

        foreach ($term->terms as $position => $word) {
            if ($relatedPath !== null) {
                $query += $this->contains("word$position", $relatedPath, $word);

                continue;
            }

            $query["filter[word$position][group][conjunction]"] = 'OR';

            foreach (array_keys($type->termFields) as $path) {
                $name = "word$position-".str_replace('.', '-', $path);
                $query += $this->contains($name, $path, $word) + ["filter[$name][condition][memberOf]" => "word$position"];
            }
        }

        $query += $this->filters($type, $filters);

        if ($active) {
            $query += array_filter(['include' => $type->include('listing'), 'sort' => $sort]);
        } else {
            $query['fields['.$type->resourceType().']'] = implode(',', array_filter([$type->titleAttribute(), ...$type->conditionAttributes()]));
        }

        return new CollectionRequest($type->nativeCollection, $query, $limit ?? $this->config->nativeLimit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(ContentType $type, SearchFilters $filters): array
    {
        $query = [];

        foreach ($type->filters as $filter) {
            $query += match ($filter->type) {
                FilterType::Equals => $this->equals($filter, $filters),
                FilterType::YearRange => $this->yearRange($filter, $filters),
                FilterType::Boolean => $this->boolean($filter, $filters),
            };
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function equals(Filter $filter, SearchFilters $filters): array
    {
        $value = $filters->value($filter->key);

        return $value === null || $value === '' || $filter->nativePath === null ? [] : ["filter[$filter->nativePath]" => $value];
    }

    /**
     * @return array<string, string>
     */
    private function yearRange(Filter $filter, SearchFilters $filters): array
    {
        $year = $filters->value($filter->key);

        if ($year === null || $filter->nativePath === null) {
            return [];
        }

        $path = $filter->nativePath;
        $utc = new DateTimeZone('UTC');

        // Exclusive bound on the next 1 January keeps the whole year in.
        return $this->dateCondition($filter->key, $path, 'from', '>=', new DateTimeImmutable("$year-01-01 00:00:00", $utc))
            + $this->dateCondition($filter->key, $path, 'to', '<', new DateTimeImmutable(((int) $year + 1).'-01-01 00:00:00', $utc));
    }

    /**
     * Only checked boxes filter, and only when every checked field has a native
     * path: a backend with index-only booleans answers the rest itself. `any`
     * puts the fields in an OR group, `all` ANDs them.
     *
     * @return array<string, mixed>
     */
    private function boolean(Filter $filter, SearchFilters $filters): array
    {
        $paths = [];

        foreach ($filter->fields as $parameter => $field) {
            if ($filters->value($parameter) !== true) {
                continue;
            }

            if ($field->native === null) {
                return [];
            }

            $paths[$parameter] = $field->native;
        }

        if ($paths === []) {
            return [];
        }

        $grouped = count($paths) > 1 && $filter->match === BooleanMatch::Any;
        $query = $grouped ? ["filter[$filter->key][group][conjunction]" => 'OR'] : [];

        foreach ($paths as $parameter => $path) {
            $field = $filter->fields[$parameter];

            // A plain equality can stay flat outside a group; everything else needs a named condition.
            if (! $grouped && ! $field->present) {
                $query["filter[$path]"] = 1;

                continue;
            }

            $query["filter[$parameter][condition][path]"] = $path;

            if ($field->present) {
                $query["filter[$parameter][condition][operator]"] = 'IS NOT NULL';
            } else {
                $query["filter[$parameter][condition][value]"] = 1;
            }

            if ($grouped) {
                $query["filter[$parameter][condition][memberOf]"] = $filter->key;
            }
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function dateCondition(string $name, string $path, string $bound, string $operator, DateTimeImmutable $at): array
    {
        return [
            "filter[{$name}_$bound][condition][path]" => $path,
            "filter[{$name}_$bound][condition][operator]" => $operator,
            "filter[{$name}_$bound][condition][value]" => $at->format('c'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function contains(string $name, string $path, string $word): array
    {
        return [
            "filter[$name][condition][path]" => $path,
            "filter[$name][condition][operator]" => 'CONTAINS',
            "filter[$name][condition][value]" => $word,
        ];
    }
}
