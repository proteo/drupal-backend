<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * Descriptor keys that earlier schema versions had and later ones removed, with
 * what replaces each. An older descriptor fails at boot with every one it still
 * uses, and a current one fails on the same list if it keeps them.
 */
final class RemovedKeys
{
    /** Keys the package rejects in a current descriptor. */
    public const array ENFORCED = [
        'facet_of', 'count_field', 'any_flag', 'link',
        'search.index', 'search.index_lookup_pages', 'search.match_strategy', 'match_strategy', 'index_bundle', 'index_fields',
        'index_field', 'boolean.index', 'condition.index', 'index_title_field', 'related',
    ];

    private const string EXTENSION = 'the `proteo/drupal-search-api` package';

    /** @var array<string, string> Name => replacement */
    private const array REPLACEMENTS = [
        'facet_of' => 'drop it: a Facet is any non-node entity type and is not tied to one Primary; relate the types with a relation and an `equals` filter',
        'count_field' => 'declare the count as an ordinary field and hide empty entries with a `conditions` rule',
        'any_flag' => 'use a `boolean` filter with `fields` and `match` (replaces `flags` and `group`)',
        'link' => 'model the related entity as a content type and reach it with a `content` relation',
        'search.index' => 'move it to `index.*` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'search.index_lookup_pages' => 'move it to `index.lookup_pages` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'search.match_strategy' => 'move it to `index.match_strategy` in `config/drupal-search-api.php` ('.self::EXTENSION.'); core matches terms natively',
        'match_strategy' => 'move it to `content_types.<key>.match_strategy` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'index_bundle' => 'move it to `content_types.<key>.bundle` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'index_fields' => 'name the columns directly under `filters` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'index_field' => 'give the filter a `native_path`; the index column goes under `filters.<key>` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'boolean.index' => 'keep `native` only; the index column goes under `filters.<key>.<parameter>` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'condition.index' => 'drop it; the index column goes under `conditions.<attribute>` in `config/drupal-search-api.php` ('.self::EXTENSION.')',
        'index_title_field' => 'rename it `title_attribute`',
        'related' => 'drop it and pass `:criteria`, `:types`, `:limit` and `:order` to `<x-drupal::related>`, or build a `RelatedRequest` at the call site',
    ];

    /**
     * @param  array<string, mixed>  $config  The `drupal-backend` config.
     * @param  list<string>|null  $only  Restrict the check to these names.
     * @return list<string> One line per removed key in use, naming its path.
     */
    public static function in(array $config, ?array $only = null): array
    {
        $found = [];
        $report = function (string $name, string $path) use ($only, &$found): void {
            if ($only === null || in_array($name, $only, true)) {
                $found[] = "$path: ".self::REPLACEMENTS[$name];
            }
        };

        $search = $config['search'] ?? null;

        if (is_array($search)) {
            foreach (['index_lookup_pages' => 'search.index_lookup_pages', 'match_strategy' => 'search.match_strategy'] as $key => $name) {
                if (array_key_exists($key, $search)) {
                    $report($name, "search.$key");
                }
            }

            foreach (array_keys((array) ($search['index'] ?? [])) as $key) {
                $report('search.index', "search.index.$key");
            }
        }

        foreach ((array) ($config['content_types'] ?? []) as $key => $type) {
            if (! is_array($type)) {
                continue;
            }

            foreach (['facet_of', 'count_field', 'match_strategy', 'index_bundle', 'index_fields', 'index_title_field', 'related'] as $name) {
                if (array_key_exists($name, $type)) {
                    $report($name, "content_types.$key.$name");
                }
            }

            foreach ((array) ($type['filters'] ?? []) as $name => $filter) {
                if (! is_array($filter)) {
                    continue;
                }

                if (($filter['type'] ?? null) === 'any_flag') {
                    $report('any_flag', "content_types.$key.filters.$name.type");
                }

                if (array_key_exists('index_field', $filter)) {
                    $report('index_field', "content_types.$key.filters.$name.index_field");
                }

                foreach ((array) ($filter['fields'] ?? []) as $parameter => $field) {
                    // Schema 2 also accepted a bare string: the index column.
                    if (is_string($field) || (is_array($field) && array_key_exists('index', $field))) {
                        $report('boolean.index', "content_types.$key.filters.$name.fields.$parameter".(is_string($field) ? '' : '.index'));
                    }
                }
            }

            foreach ((array) ($type['conditions'] ?? []) as $position => $condition) {
                if (is_array($condition) && array_key_exists('index', $condition)) {
                    $report('condition.index', "content_types.$key.conditions.$position.index");
                }
            }

            foreach ((array) ($type['relations'] ?? []) as $name => $relation) {
                if (is_array($relation) && ($relation['type'] ?? null) === 'link') {
                    $report('link', "content_types.$key.relations.$name.type");
                }
            }
        }

        return $found;
    }
}
