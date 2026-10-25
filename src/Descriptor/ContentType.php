<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * One Drupal (entity type, bundle) pair and how the frontend serves it.
 */
final readonly class ContentType
{
    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, Relation>  $relations
     * @param  array<string, list<string>>  $includes  Relation names per context (`item`, `listing`).
     * @param  array<string, Filter>  $filters
     * @param  array<string, string>  $sorts  Sort name to upstream sort.
     * @param  list<Condition>  $conditions  Rules the entities must satisfy to appear in lists, search and suggestions.
     * @param  array<string, int>  $termFields  Own fields matched by a typed term, with weights.
     * @param  array<string, int>  $relatedTermFields  Related paths matched by a typed term, with weights.
     * @param  array<string, string>  $referencedBy  Content type key to the field holding this type's UUID.
     * @param  array<string, string>  $routes  Page slug to URL.
     * @param  array{item?: string, index?: string}  $views
     * @param  ?class-string  $entity  Optional project subclass of the Entity base.
     * @param  array<string, string>  $dottedIncludes  Dotted include entries (`documents.file`) to their expanded path.
     * @param  ?class-string  $decorator  Optional EntityDecorator adding extras to the type's entities.
     * @param  ?string  $suggestSubtitle  Content relation whose first entry's name is shown beside a search suggestion.
     * @param  bool  $served  Whether the item and listing routes exist: a node or term has an alias; any other entity type opts in with `url_prefix` or `listing_path`.
     */
    public function __construct(
        public string $key,
        public Role $role,
        public string $source,
        public string $entityType,
        public string $bundle,
        public string $nativeCollection,
        public string $urlPrefix,
        public string $aliasPrefix,
        public ?string $listingPath = null,
        public array $fields = [],
        public array $relations = [],
        public array $includes = [],
        public array $filters = [],
        public array $sorts = [],
        public bool $searchable = false,
        public array $conditions = [],
        public ?string $titleAttribute = null,
        public ?string $defaultSort = null,
        public ?int $perPage = null,
        public array $termFields = [],
        public array $relatedTermFields = [],
        public array $referencedBy = [],
        public bool $optional = false,
        public ?SeoConfig $seo = null,
        public array $views = [],
        public ?string $entity = null,
        public array $routes = [],
        public ?string $suggestSubtitle = null,
        public bool $served = true,
        public ?string $decorator = null,
        public array $dottedIncludes = [],
    ) {}

    /**
     * JSON:API resource name, e.g. "node--talk".
     */
    public function resourceType(): string
    {
        return $this->entityType.'--'.$this->bundle;
    }

    /**
     * Path under the JSON:API root, e.g. "node/talk".
     */
    public function resourcePath(): string
    {
        return $this->entityType.'/'.$this->bundle;
    }

    /**
     * Whether Drupal gives the entity a path alias: only nodes and terms do.
     */
    public function hasAlias(): bool
    {
        return $this->entityType === 'node' || $this->entityType === 'taxonomy_term';
    }

    /**
     * Rules sent upstream with a native request.
     *
     * @return list<Condition>
     */
    public function filterableConditions(): array
    {
        return array_values(array_filter($this->conditions, fn (Condition $c): bool => $c->filterable));
    }

    /**
     * Native rules tested in PHP after the page is fetched.
     *
     * @return list<Condition>
     */
    public function postFilterConditions(): array
    {
        return array_values(array_filter($this->conditions, fn (Condition $c): bool => ! $c->filterable));
    }

    /**
     * Query parameters that send the filterable native rules upstream.
     *
     * @return array<string, mixed>
     */
    public function conditionQuery(): array
    {
        $query = [];
        foreach ($this->filterableConditions() as $condition) {
            $query += $condition->query($condition->attribute);
        }

        return $query;
    }

    /**
     * Native fields the post-filter reads.
     *
     * @return list<string>
     */
    public function conditionAttributes(): array
    {
        return array_values(array_unique(array_map(fn (Condition $c): string => $c->attribute, $this->conditions)));
    }

    /**
     * Whether a native resource passes the rules tested in PHP.
     *
     * @param  array<string, mixed>  $resource
     */
    public function isVisible(array $resource): bool
    {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];

        foreach ($this->postFilterConditions() as $condition) {
            if (! $condition->matches($attributes)) {
                return false;
            }
        }

        return true;
    }

    public function hasListing(): bool
    {
        return $this->listingPath !== null;
    }

    /**
     * The `include` parameter of a context: each relation's path, and for an image the chain to its file.
     */
    public function include(string $context): string
    {
        return $this->includeOf($this->includes[$context] ?? []);
    }

    /**
     * The `include` parameter for the named relations. A dotted name (`documents.file`) goes one level
     * deeper: the relation, then a relation of its target type.
     *
     * @param  list<string>  $names
     */
    public function includeOf(array $names): string
    {
        return implode(',', array_map(function (string $name): string {
            return $this->dottedIncludes[$name] ?? $this->relations[$name]->includePath();
        }, $names));
    }

    /**
     * The attribute holding the item's display name.
     */
    public function titleAttribute(): ?string
    {
        return $this->titleAttribute ?? $this->sorts['title'] ?? array_key_first($this->termFields);
    }

    /**
     * The public URL of an item: the URL prefix plus its alias without the alias prefix.
     */
    public function url(string $path): string
    {
        $alias = ltrim($path, '/');

        if ($this->aliasPrefix !== '' && str_starts_with($alias, $this->aliasPrefix.'/')) {
            $alias = substr($alias, strlen($this->aliasPrefix) + 1);
        }

        return url(trim($this->urlPrefix.'/'.$alias, '/'));
    }
}
