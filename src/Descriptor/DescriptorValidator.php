<?php

namespace Proteo\DrupalBackend\Descriptor;

use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\EntityDecorator;
use Proteo\DrupalBackend\Search\Capabilities;

/**
 * Offline checks that the parts of a descriptor fit together. No network.
 */
class DescriptorValidator
{
    /** Names the Entity base class owns. */
    public const array BUILT_IN_NAMES = ['id', 'type', 'path', 'url'];

    /** URL parameters of the search page. */
    public const array RESERVED_PARAMETERS = ['q', 'sort', 'tab', 'page'];

    /**
     * @param  ?Capabilities  $capabilities  What the bound search backend can do; without it the rules that depend on it are skipped,
     *                                       since the descriptor loads before the backend can be resolved.
     * @return list<string> One message per problem; empty when the descriptor is sound.
     */
    public function problems(Descriptor $descriptor, ?Capabilities $capabilities = null): array
    {
        $problems = [];

        $this->sources($descriptor, $problems);

        foreach ($descriptor->contentTypes as $type) {
            $this->contentType($descriptor, $type, $capabilities, $problems);
        }

        $this->pages($descriptor, $problems);
        $this->listings($descriptor, $problems);
        $this->search($descriptor, $capabilities, $problems);

        return $problems;
    }

    /**
     * @param  list<string>  $problems
     */
    private function sources(Descriptor $descriptor, array &$problems): void
    {
        if (! isset($descriptor->sources[$descriptor->defaultSource])) {
            $problems[] = "sources: the default source [$descriptor->defaultSource] is not declared.";
        }

        foreach ($descriptor->sources as $source) {
            foreach ($source->flushAlsoSources as $other) {
                if (! isset($descriptor->sources[$other])) {
                    $problems[] = "sources.$source->name.flush.also.sources: unknown source [$other].";
                }
            }
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function contentType(Descriptor $descriptor, ContentType $type, ?Capabilities $capabilities, array &$problems): void
    {
        $at = "content_types.$type->key";

        if (! isset($descriptor->sources[$type->source])) {
            $problems[] = "$at.source: unknown source [$type->source].";
        }

        foreach (array_keys($type->fields) as $name) {
            if (in_array($name, self::BUILT_IN_NAMES, true)) {
                $problems[] = "$at.fields.$name: [$name] is a built-in name of every entity and cannot be a field.";
            }
            if (isset($type->relations[$name])) {
                $problems[] = "$at: [$name] is declared as both a field and a relation.";
            }
        }

        foreach ($type->relations as $relation) {
            $this->relation($descriptor, $type, $relation, $problems);
        }

        foreach ($type->includes as $context => $names) {
            foreach ($names as $name) {
                if (str_contains($name, '.')) {
                    $this->dottedInclude($descriptor, $type, $name, "$at.includes.$context", $problems);
                } elseif (! isset($type->relations[$name])) {
                    $problems[] = "$at.includes.$context: unknown relation [$name].";
                }
            }
        }

        $this->role($descriptor, $type, $problems);
        $this->conditions($type, $problems);

        $parameters = [];
        foreach ($type->filters as $filter) {
            $this->filter($descriptor, $type, $filter, $capabilities, $problems);

            foreach ($filter->parameters() as $parameter) {
                if (in_array($parameter, self::RESERVED_PARAMETERS, true)) {
                    $problems[] = "$at.filters.$filter->key: [$parameter] is reserved for the search page.";
                }
                if (isset($parameters[$parameter])) {
                    $problems[] = "$at.filters.$filter->key: the URL parameter [$parameter] is already used by filter [{$parameters[$parameter]}].";
                }
                $parameters[$parameter] = $filter->key;
            }
        }

        if ($type->seo !== null) {
            foreach (['title' => $type->seo->title, 'description' => $type->seo->description, 'image' => $type->seo->image, 'author' => $type->seo->author] as $slot => $name) {
                if ($name !== null && ! isset($type->fields[$name]) && ! isset($type->relations[$name])) {
                    $problems[] = "$at.seo.$slot: unknown field or relation [$name].";
                }
            }
        }

        if ($type->entity !== null && ! is_a($type->entity, Entity::class, true)) {
            $problems[] = "$at.entity: [$type->entity] must be a class extending ".Entity::class.'.';
        }

        if ($type->decorator !== null && ! is_a($type->decorator, EntityDecorator::class, true)) {
            $problems[] = "$at.decorator: [$type->decorator] must be a class implementing ".EntityDecorator::class.'.';
        }

        if ($type->routes !== [] && $type->role !== Role::Page) {
            $problems[] = "$at.routes: only a Page has routes.";
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function relation(Descriptor $descriptor, ContentType $type, Relation $relation, array &$problems): void
    {
        $at = "content_types.$type->key.relations.$relation->name";

        if (in_array($relation->name, self::BUILT_IN_NAMES, true)) {
            $problems[] = "$at: [$relation->name] is a built-in name of every entity and cannot be a relation.";
        }

        if ($relation->kind === RelationKind::Content) {
            if ($relation->to === null || ! isset($descriptor->contentTypes[$relation->to])) {
                $problems[] = "$at.to: unknown content type [".($relation->to ?? '').'].';
            }
        } elseif ($relation->to !== null) {
            $problems[] = "$at.to: a {$relation->kind->value} relation points at no content type.";
        }

        if ($relation->kind === RelationKind::File && $relation->many) {
            $problems[] = "$at.many: a file relation holds one file.";
        }

        if ($relation->imageField !== null && $relation->kind !== RelationKind::Image) {
            $problems[] = "$at.image_field: only an image relation has an image field.";
        }
    }

    /**
     * A dotted include is a relation of the type followed by a relation of that relation's target type.
     *
     * @param  list<string>  $problems
     */
    private function dottedInclude(Descriptor $descriptor, ContentType $type, string $name, string $at, array &$problems): void
    {
        $segments = explode('.', $name);

        if (count($segments) !== 2) {
            $problems[] = "$at: [$name] goes more than one level deep.";

            return;
        }

        $first = $type->relations[$segments[0]] ?? null;

        if ($first === null) {
            $problems[] = "$at: unknown relation [{$segments[0]}] in [$name].";
        } elseif ($first->kind !== RelationKind::Content || ! isset($descriptor->contentTypes[(string) $first->to])) {
            $problems[] = "$at: [{$segments[0]}] in [$name] must be a content relation to follow it.";
        } elseif (! isset($descriptor->contentTypes[(string) $first->to]->relations[$segments[1]])) {
            $problems[] = "$at: content type [$first->to] has no relation [{$segments[1]}] in [$name].";
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function role(Descriptor $descriptor, ContentType $type, array &$problems): void
    {
        $at = "content_types.$type->key";

        if ($type->suggestSubtitle !== null && ($type->relations[$type->suggestSubtitle] ?? null)?->kind !== RelationKind::Content) {
            $problems[] = "$at.suggest_subtitle: [$type->suggestSubtitle] is not a content relation of this type.";
        }

        // The entity type decides the role; `role` may only narrow a node to a Page or Reference.
        $isNode = $type->entityType === 'node';
        if ($isNode && $type->role === Role::Facet) {
            $problems[] = "$at.role: a node is a Primary, Page or Reference, not a Facet.";
        }
        if (! $isNode && $type->role !== Role::Facet) {
            $problems[] = "$at.role: [$type->entityType] is not a node, so the content type is a Facet, not a {$type->role->value}.";
        }

        if ($type->searchable && ($type->role === Role::Page || $type->role === Role::Reference)) {
            $problems[] = "$at.searchable: a {$type->role->value} is never searchable.";
        }

        if ($type->role === Role::Facet) {
        }

        if ($type->role === Role::Reference) {
            if ($type->referencedBy === []) {
                $problems[] = "$at.referenced_by: a Reference is fetched through a field of another content type.";
            }
            foreach ($type->referencedBy as $holder => $field) {
                if (! isset($descriptor->contentTypes[$holder]->fields[$field])) {
                    $problems[] = "$at.referenced_by: content type [$holder] has no field [$field].";
                }
            }
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function conditions(ContentType $type, array &$problems): void
    {
        foreach ($type->conditions as $position => $condition) {
            $at = "content_types.$type->key.conditions.$position";

            if (! in_array($condition->operator, Condition::OPERATORS, true)) {
                $problems[] = "$at.operator: [$condition->operator] is not one of ".implode(', ', Condition::OPERATORS).'.';

                continue;
            }

            $isList = in_array($condition->operator, Condition::LISTS, true);
            $isNull = in_array($condition->operator, Condition::NULLS, true);

            if ($isNull && $condition->value !== null) {
                $problems[] = "$at.value: [$condition->operator] takes no value.";
            } elseif ($isList && (! is_array($condition->value) || $condition->value === [] || array_filter($condition->value, fn (mixed $v): bool => ! is_scalar($v)) !== [])) {
                $problems[] = "$at.value: [$condition->operator] takes a non-empty list of scalars.";
            } elseif (! $isList && ! $isNull && ! is_scalar($condition->value)) {
                $problems[] = "$at.value: [$condition->operator] takes one scalar value.";
            }
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function filter(Descriptor $descriptor, ContentType $type, Filter $filter, ?Capabilities $capabilities, array &$problems): void
    {
        $at = "content_types.$type->key.filters.$filter->key";

        switch ($filter->type) {
            case FilterType::Equals:
                $relation = $filter->relation === null ? null : ($type->relations[$filter->relation] ?? null);
                if ($relation === null || $relation->kind !== RelationKind::Content) {
                    $problems[] = "$at.relation: an equals filter compares against a content relation, got [".($filter->relation ?? '').'].';
                } elseif ($descriptor->search->enabled && $type->searchable && ($target = $descriptor->contentTypes[$relation->to] ?? null) !== null && $target->titleAttribute === null) {
                    // The choices of the filter are the target's entries, listed by their title.
                    $problems[] = "content_types.$target->key.title_attribute: required to offer the choices of filter [$filter->key].";
                }
                if ($filter->nativePath === null) {
                    $problems[] = "$at.native_path: an equals filter needs the filter path on the native collection.";
                }
                break;
            case FilterType::YearRange:
                if ($filter->nativePath === null) {
                    $problems[] = "$at.native_path: a year range needs the date path on the native collection.";
                }
                if ($filter->firstYear === null) {
                    $problems[] = "$at.first_year: a year range needs its first year.";
                }
                break;
            case FilterType::Boolean:
                if ($filter->fields === []) {
                    $problems[] = "$at.fields: a boolean filter needs at least one field.";
                }
                foreach ($filter->fields as $parameter => $field) {
                    if ($field->native === null && $capabilities !== null && ! $capabilities->indexOnlyBooleans) {
                        $problems[] = "$at.fields.$parameter.native: required; the bound search backend cannot answer a boolean that exists only in an index.";
                    }
                }
                break;
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function pages(Descriptor $descriptor, array &$problems): void
    {
        $slugs = [];

        foreach ($descriptor->ofRole(Role::Page) as $page) {
            foreach ($page->routes as $slug => $url) {
                if (isset($slugs[$slug])) {
                    $problems[] = "content_types.$page->key.routes: the slug [$slug] is already used by [{$slugs[$slug]}].";
                }
                $slugs[$slug] = $page->key;

                if (! str_starts_with($url, '/')) {
                    $problems[] = "content_types.$page->key.routes.$slug: the URL must start with a slash.";
                }
            }
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function listings(Descriptor $descriptor, array &$problems): void
    {
        $paths = [];

        foreach ($descriptor->contentTypes as $type) {
            if ($type->listingPath === null) {
                continue;
            }
            if (isset($paths[$type->listingPath])) {
                $problems[] = "content_types.$type->key.listing_path: [$type->listingPath] is already used by [{$paths[$type->listingPath]}].";
            }
            $paths[$type->listingPath] = $type->key;
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private function search(Descriptor $descriptor, ?Capabilities $capabilities, array &$problems): void
    {
        $search = $descriptor->search;

        if (! $search->enabled) {
            return;
        }

        $searchable = $descriptor->searchableTypes();

        if ($searchable === []) {
            $problems[] = 'search: at least one searchable content type is required.';
        }

        $seen = [];

        foreach ($search->tabs as $key) {
            $tab = $descriptor->contentTypes[$key] ?? null;

            if ($tab === null) {
                $problems[] = "search.tabs: unknown content type [$key].";

                continue;
            }
            if (isset($seen[$key])) {
                $problems[] = "search.tabs: [$key] is listed twice.";
            }
            $seen[$key] = true;

            if (! $tab->searchable) {
                $problems[] = "search.tabs: [$key] is not searchable.";
            }
        }

        $filters = [];

        foreach ($searchable as $type) {
            $at = "content_types.$type->key";

            // A backend with its own fulltext may match a type without term fields.
            if ($type->termFields === [] && $capabilities !== null && ! $capabilities->fulltext) {
                $problems[] = "$at.term_fields: a searchable type needs at least one field to match a term against.";
            }

            foreach (array_keys($type->filters) as $name) {
                foreach ($type->filters[$name]->parameters() as $parameter) {
                    if (isset($filters[$parameter]) && $filters[$parameter] !== $type->key) {
                        $problems[] = "$at.filters.$name: [$parameter] is already used by [{$filters[$parameter]}]; filter keys are unique across types.";
                    }
                    $filters[$parameter] ??= $type->key;
                }
            }
        }

        foreach (array_keys($search->suggestionQuotas) as $key) {
            if (! isset($descriptor->contentTypes[$key])) {
                $problems[] = "search.suggestions.quotas: unknown content type [$key].";
            }
        }
    }
}
