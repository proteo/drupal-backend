<?php

namespace Proteo\DrupalBackend\Descriptor;

use BackedEnum;

/**
 * Turns the descriptor array into typed objects and applies the code defaults.
 *
 * Only shape errors are raised here (missing keys, unknown enum values, wrong
 * types); whether the pieces fit together is the validator's job.
 */
class DescriptorFactory
{
    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidDescriptor
     */
    public function make(array $config): Descriptor
    {
        $version = $config['schema_version'] ?? null;

        if ($version !== Descriptor::SCHEMA_VERSION) {
            $removed = $version === 1 || $version === 2 ? RemovedKeys::in($config) : [];

            throw $removed === [] ? InvalidDescriptor::schemaMismatch($version) : InvalidDescriptor::removedKeys($removed);
        }

        if (($removed = RemovedKeys::in($config, RemovedKeys::ENFORCED)) !== []) {
            throw InvalidDescriptor::removedKeys($removed);
        }

        $sources = [];
        foreach ($this->map($config, 'sources') as $name => $source) {
            $sources[(string) $name] = $this->source((string) $name, $this->array($source, "sources.$name"));
        }

        $default = $config['default_source'] ?? (isset($sources['default']) ? 'default' : array_key_first($sources));

        $types = [];
        foreach ($this->map($config, 'content_types') as $key => $type) {
            $types[(string) $key] = $this->contentType((string) $key, $this->array($type, "content_types.$key"), (string) $default, $this->map($config, 'content_types'));
        }

        return new Descriptor(
            sources: $sources,
            defaultSource: (string) $default,
            contentTypes: $types,
            search: $this->search($this->map($config, 'search'), array_keys(array_filter($types, fn (ContentType $type): bool => $type->searchable))),
            routes: $this->routes($this->map($config, 'routes')),
            webhook: $this->webhook($this->map($config, 'webhook')),
            limits: $this->limits($this->map($config, 'limits')),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function source(string $name, array $data): Source
    {
        $webhook = $this->map($data, 'webhook');
        $flush = $this->map($this->map($data, 'flush'), 'also');

        return new Source(
            name: $name,
            baseUrl: rtrim($this->string($data, 'base_url', "sources.$name"), '/'),
            jsonapiPath: '/'.(trim((string) ($data['jsonapi_path'] ?? ''), '/') ?: 'jsonapi'),
            filesPath: ($files = trim((string) ($data['files_path'] ?? ''), '/')) === '' ? '' : '/'.$files,
            frontendUrl: isset($data['frontend_url']) && $data['frontend_url'] !== '' ? rtrim((string) $data['frontend_url'], '/') : null,
            webhookSecret: isset($webhook['secret']) && $webhook['secret'] !== '' ? (string) $webhook['secret'] : null,
            webhookTolerance: isset($webhook['tolerance']) ? (int) $webhook['tolerance'] : null,
            flushAlsoSources: array_values(array_map('strval', (array) ($flush['sources'] ?? []))),
            overrides: array_diff_key($data, array_flip(DescriptorSchema::sourceKeys())),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawTypes  Every content type's raw configuration, to expand dotted includes.
     */
    private function contentType(string $key, array $data, string $defaultSource, array $rawTypes): ContentType
    {
        $at = "content_types.$key";
        $entityType = $this->string($data, 'entity_type', $at);
        // Only a node can be a Primary; `role` is written to pick a Page or Reference instead.
        $role = isset($data['role'])
            ? $this->enum(Role::class, $data['role'], "$at.role")
            : ($entityType === 'node' ? Role::Primary : Role::Facet);
        $bundle = isset($data['bundle']) ? $this->string($data, 'bundle', $at) : $entityType;
        $searchable = (bool) ($data['searchable'] ?? $role === Role::Primary);

        $fields = [];
        foreach ($this->map($data, 'fields') as $name => $field) {
            $fields[(string) $name] = $this->field((string) $name, $this->array($field, "$at.fields.$name"), "$at.fields.$name");
        }

        $relations = [];
        foreach ($this->map($data, 'relations') as $name => $relation) {
            $relations[(string) $name] = $this->relation((string) $name, $this->array($relation, "$at.relations.$name"), "$at.relations.$name");
        }

        $filters = [];
        foreach ($this->map($data, 'filters') as $name => $filter) {
            $filters[(string) $name] = $this->filter((string) $name, $this->array($filter, "$at.filters.$name"), "$at.filters.$name");
        }

        $conditions = [];
        foreach ($this->map($data, 'conditions') as $position => $condition) {
            $conditions[] = $this->condition($this->array($condition, "$at.conditions.$position"), "$at.conditions.$position");
        }

        return new ContentType(
            key: $key,
            role: $role,
            source: (string) ($data['source'] ?? $defaultSource),
            entityType: $entityType,
            bundle: $bundle,
            nativeCollection: (string) ($data['native_collection'] ?? $bundle),
            urlPrefix: trim((string) ($data['url_prefix'] ?? $key), '/'),
            aliasPrefix: trim((string) ($data['alias_prefix'] ?? $key), '/'),
            listingPath: isset($data['listing_path']) ? '/'.trim((string) $data['listing_path'], '/') : null,
            fields: $fields,
            relations: $relations,
            includes: array_map(fn (mixed $names): array => array_values(array_map('strval', (array) $names)), $this->map($data, 'includes')),
            filters: $filters,
            sorts: array_map('strval', $this->map($data, 'sorts')),
            searchable: $searchable,
            conditions: $conditions,
            titleAttribute: isset($data['title_attribute']) ? (string) $data['title_attribute'] : null,
            defaultSort: isset($data['default_sort']) ? (string) $data['default_sort'] : null,
            perPage: isset($data['per_page']) ? (int) $data['per_page'] : null,
            termFields: array_key_exists('term_fields', $data)
                ? array_map('intval', $this->map($data, 'term_fields'))
                : ($searchable ? [$this->defaultTitleAttribute($data, $entityType) => 1] : []),
            relatedTermFields: array_map('intval', $this->map($data, 'related_term_fields')),
            referencedBy: array_map('strval', $this->map($data, 'referenced_by')),
            optional: (bool) ($data['optional'] ?? $role === Role::Reference),
            seo: isset($data['seo']) ? $this->seo($this->array($data['seo'], "$at.seo")) : null,
            views: array_map('strval', $this->map($data, 'views')),
            entity: isset($data['entity']) ? (string) $data['entity'] : null,
            routes: array_map('strval', $this->map($data, 'routes')),
            suggestSubtitle: isset($data['suggest_subtitle']) ? (string) $data['suggest_subtitle'] : null,
            dottedIncludes: $this->dottedIncludes($relations, $this->map($data, 'includes'), $rawTypes, $at),
            decorator: isset($data['decorator']) ? (string) $data['decorator'] : null,
            served: $role !== Role::Page && $role !== Role::Reference && (in_array($entityType, ['node', 'taxonomy_term'], true) || isset($data['url_prefix']) || isset($data['listing_path'])),
        );
    }

    /**
     * What a searchable type matches a typed term against when it names no `term_fields`.
     *
     * @param  array<string, mixed>  $data
     */
    private function defaultTitleAttribute(array $data, string $entityType): string
    {
        return (string) ($data['title_attribute'] ?? $this->map($data, 'sorts')['title'] ?? ($entityType === 'node' ? 'title' : 'name'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function condition(array $data, string $at): Condition
    {
        return new Condition(
            attribute: $this->string($data, 'attribute', $at),
            operator: strtoupper($this->string($data, 'operator', $at)),
            value: $data['value'] ?? null,
            filterable: (bool) ($data['filterable'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function field(string $name, array $data, string $at): Field
    {
        return new Field(
            name: $name,
            source: $this->string($data, 'source', $at),
            type: $this->enum(FieldType::class, $data['type'] ?? 'string', "$at.type"),
            required: (bool) ($data['required'] ?? false),
            length: isset($data['length']) ? (int) $data['length'] : null,
        );
    }

    /**
     * Expands each `relation.relation` include entry one level; an entry that does not resolve is left
     * for the validator to report.
     *
     * @param  array<string, Relation>  $relations
     * @param  array<string, mixed>  $includes
     * @param  array<string, mixed>  $rawTypes
     * @return array<string, string>
     */
    private function dottedIncludes(array $relations, array $includes, array $rawTypes, string $at): array
    {
        $expanded = [];

        foreach ($includes as $names) {
            foreach ((array) $names as $name) {
                [$first, $second] = array_pad(explode('.', (string) $name, 2), 2, null);
                $target = $second === null ? null : ($relations[$first] ?? null)?->to;
                $raw = $target === null ? null : data_get($rawTypes, "$target.relations.$second");

                if (is_array($raw)) {
                    $expanded[(string) $name] = $relations[$first]->via.'.'.$this->relation($second, $raw, "$at.includes")->includePath();
                }
            }
        }

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function relation(string $name, array $data, string $at): Relation
    {
        return new Relation(
            name: $name,
            kind: $this->enum(RelationKind::class, $data['type'] ?? 'content', "$at.type"),
            via: $this->string($data, 'via', $at),
            to: isset($data['to']) ? (string) $data['to'] : null,
            many: (bool) ($data['many'] ?? false),
            optional: (bool) ($data['optional'] ?? false),
            imageField: isset($data['image_field']) ? (string) $data['image_field'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filter(string $key, array $data, string $at): Filter
    {
        return new Filter(
            key: $key,
            type: $this->enum(FilterType::class, $data['type'] ?? null, "$at.type"),
            relation: isset($data['relation']) ? (string) $data['relation'] : null,
            nativePath: isset($data['native_path']) ? (string) $data['native_path'] : null,
            firstYear: isset($data['first_year']) ? (int) $data['first_year'] : null,
            fields: $this->booleanFields($data, $at),
            match: $this->enum(BooleanMatch::class, $data['match'] ?? 'any', "$at.match"),
            label: isset($data['label']) ? (string) $data['label'] : null,
            labels: array_map('strval', $this->map($data, 'labels')),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, BooleanField>
     */
    private function booleanFields(array $data, string $at): array
    {
        $fields = [];

        foreach ($this->map($data, 'fields') as $parameter => $field) {
            $field = $this->array($field, "$at.fields.$parameter");
            $fields[(string) $parameter] = new BooleanField(isset($field['native']) ? (string) $field['native'] : null, (bool) ($field['present'] ?? false));
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seo(array $data): SeoConfig
    {
        // The description may be a bare field name or a field with a length cap.
        $description = $data['description'] ?? null;
        $descriptionField = is_array($description) ? ($description['field'] ?? null) : $description;

        return new SeoConfig(
            title: isset($data['title']) ? (string) $data['title'] : null,
            description: $descriptionField === null ? null : (string) $descriptionField,
            descriptionMax: is_array($description) ? (int) ($description['max'] ?? 160) : 160,
            image: isset($data['image']) ? (string) $data['image'] : null,
            author: isset($data['author']) ? (string) $data['author'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $searchableTypes  The tabs when `search.tabs` is not set.
     */
    private function search(array $data, array $searchableTypes): SearchConfig
    {
        $suggestions = $this->map($data, 'suggestions');
        $term = $this->map($data, 'term');
        $defaults = new SearchConfig;

        return new SearchConfig(
            enabled: (bool) ($data['enabled'] ?? $defaults->enabled),
            tabs: array_values(array_map('strval', (array) ($data['tabs'] ?? $searchableTypes))),
            perPage: (int) ($data['per_page'] ?? $defaults->perPage),
            nativeLimit: (int) ($data['native_limit'] ?? $defaults->nativeLimit),
            cacheSeconds: (int) ($data['cache'] ?? $defaults->cacheSeconds),
            suggestionLimit: (int) ($suggestions['limit'] ?? $defaults->suggestionLimit),
            suggestionQuotas: array_map('intval', $this->map($suggestions, 'quotas')),
            termMin: (int) ($term['min'] ?? $defaults->termMin),
            termMax: (int) ($term['max'] ?? $defaults->termMax),
            termMaxWords: (int) ($term['max_words'] ?? $defaults->termMaxWords),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function routes(array $data): RoutesConfig
    {
        $defaults = new RoutesConfig;

        return new RoutesConfig(
            auto: (bool) ($data['auto'] ?? $defaults->auto),
            prefix: trim((string) ($data['prefix'] ?? $defaults->prefix), '/'),
            middleware: array_values(array_map('strval', (array) ($data['middleware'] ?? $defaults->middleware))),
            aliasPattern: (string) ($data['alias_pattern'] ?? $defaults->aliasPattern),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function webhook(array $data): WebhookConfig
    {
        $defaults = new WebhookConfig;

        return new WebhookConfig(
            path: '/'.trim((string) ($data['path'] ?? $defaults->path), '/'),
            tolerance: (int) ($data['tolerance'] ?? $defaults->tolerance),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function limits(array $data): LimitsConfig
    {
        $defaults = new LimitsConfig;

        return new LimitsConfig(
            content: (int) ($data['content'] ?? $defaults->content),
            searchSuggest: (int) ($data['search_suggest'] ?? $defaults->searchSuggest),
            drupalWebhook: (int) ($data['drupal_webhook'] ?? $defaults->drupalWebhook),
        );
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $enum, mixed $value, string $at): BackedEnum
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        return $case ?? throw InvalidDescriptor::because($at, sprintf(
            'expected one of [%s], got [%s]',
            implode(', ', array_map(fn (BackedEnum $c): string => (string) $c->value, $enum::cases())),
            is_scalar($value) ? (string) $value : gettype($value),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key, string $at): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw InvalidDescriptor::because("$at.$key", 'a string is required');
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value, string $at): array
    {
        return is_array($value) ? $value : throw InvalidDescriptor::because($at, 'an array is required');
    }

    /**
     * An optional sub-array; absent means empty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function map(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? $value : throw InvalidDescriptor::because($key, 'an array is required');
    }
}
