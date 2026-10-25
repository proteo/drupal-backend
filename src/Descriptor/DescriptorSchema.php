<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * Every key of the `drupal-backend` config, by path: the one place that says
 * which keys exist. The factory reads its known Source keys from here, the
 * validators warn about keys outside it.
 *
 * In a tree, `true` is a leaf (a scalar, a list, or a map the package does not
 * look inside) and the `*` key stands for any name the project chooses.
 */
final class DescriptorSchema
{
    /**
     * Paths where a reference page starts a new table. A key belongs to the
     * longest of these that is a proper prefix of its path.
     */
    public const array SECTIONS = [
        '',
        'sources.*',
        'search',
        'routes',
        'webhook',
        'limits',
        'content_types.*',
        'content_types.*.fields.*',
        'content_types.*.relations.*',
        'content_types.*.filters.*',
        'content_types.*.conditions.*',
        'content_types.*.seo',
    ];

    /** Sections documented on the Descriptor reference page; the rest are on the Configuration reference. */
    public const string DESCRIPTOR_SECTION = 'content_types.*';

    /**
     * Shipped defaults in `config/drupal.php`, merged by the provider; a project's own file wins.
     *
     * @var list<string>
     */
    public const array APP_CONFIG = [
        'drupal.connect_timeout',
        'drupal.timeout',
        'drupal.retries',
        'drupal.retry_delay_ms',
        'drupal.cache.enabled',
        'drupal.cache.fresh',
        'drupal.cache.stale',
        'drupal.cache.negative',
        'drupal.cache.store',
        'drupal.related.max_requests',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function tree(): array
    {
        $source = [
            'base_url' => true,
            'jsonapi_path' => true,
            'files_path' => true,
            'frontend_url' => true,
            'webhook' => ['secret' => true, 'tolerance' => true],
            'flush' => ['also' => ['sources' => true]],
            // Per-Source HTTP overrides read by DrupalManager.
            'timeout' => true,
            'connect_timeout' => true,
            'retries' => true,
            'retry_delay_ms' => true,
        ];

        $type = [
            'role' => true,
            'entity_type' => true,
            'bundle' => true,
            'source' => true,
            'native_collection' => true,
            'url_prefix' => true,
            'alias_prefix' => true,
            'listing_path' => true,
            'per_page' => true,
            'default_sort' => true,
            'fields' => ['*' => ['source' => true, 'type' => true, 'required' => true, 'length' => true]],
            'relations' => ['*' => [
                'type' => true, 'via' => true, 'to' => true, 'many' => true, 'optional' => true, 'image_field' => true,
            ]],
            'includes' => ['*' => true],
            'filters' => ['*' => [
                'type' => true, 'relation' => true, 'native_path' => true, 'first_year' => true,
                'fields' => ['*' => true], 'match' => true, 'label' => true, 'labels' => ['*' => true],
            ]],
            'sorts' => ['*' => true],
            'searchable' => true,
            'conditions' => ['*' => ['attribute' => true, 'operator' => true, 'value' => true, 'filterable' => true]],
            'title_attribute' => true,
            'term_fields' => ['*' => true],
            'related_term_fields' => ['*' => true],
            'referenced_by' => ['*' => true],
            'optional' => true,
            'seo' => ['title' => true, 'description' => true, 'image' => true, 'author' => true],
            'views' => ['item' => true, 'index' => true],
            'entity' => true,
            'decorator' => true,
            'routes' => ['*' => true],
            'suggest_subtitle' => true,
        ];

        return [
            'schema_version' => true,
            'sources' => ['*' => $source],
            'default_source' => true,
            'content_types' => ['*' => $type],
            'search' => [
                'enabled' => true,
                'tabs' => true,
                'per_page' => true,
                'native_limit' => true,
                'cache' => true,
                'suggestions' => ['limit' => true, 'quotas' => ['*' => true]],
                'term' => ['min' => true, 'max' => true, 'max_words' => true],
            ],
            'routes' => ['auto' => true, 'prefix' => true, 'middleware' => true, 'alias_pattern' => true],
            'webhook' => ['path' => true, 'tolerance' => true],
            'limits' => ['content' => true, 'search_suggest' => true, 'drupal_webhook' => true],
        ];
    }

    /**
     * The keys a Source understands itself; anything else is an HTTP override.
     *
     * @return list<string>
     */
    public static function sourceKeys(): array
    {
        return ['base_url', 'jsonapi_path', 'files_path', 'frontend_url', 'webhook', 'flush'];
    }

    /**
     * Every documentable key, grouped by the section's table, relative to it
     * (`suggestions.limit`, not `search.suggestions.limit`). A section's own head
     * appears in its parent (`fields`, `search`).
     *
     * @return array<string, list<string>> Section path => keys
     */
    public static function sections(): array
    {
        $sections = array_fill_keys(self::SECTIONS, []);

        foreach (self::rows(self::tree(), '') as $path) {
            $section = self::sectionOf($path);
            $sections[$section][] = $section === '' ? $path : substr($path, strlen($section) + 1);
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $config  The `drupal-backend` config.
     * @return list<string> One message per key the schema does not know.
     */
    public static function unknownKeys(array $config): array
    {
        $problems = [];

        self::walk(self::tree(), $config, '', $problems);

        return $problems;
    }

    /**
     * Leaves, and the heads of the sections that get their own table.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function rows(array $node, string $prefix): array
    {
        $rows = [];

        foreach ($node as $key => $child) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";

            if ($key === '*') {
                if (is_array($child)) {
                    // The wildcard itself is not a row; its children are the section's rows.
                    array_push($rows, ...self::rows($child, $path));
                }

                continue;
            }

            $isLeaf = $child === true || array_keys($child) === ['*'] && $child['*'] === true;
            $isHead = in_array($path, self::SECTIONS, true) || in_array("$path.*", self::SECTIONS, true);

            if ($isLeaf || $isHead) {
                $rows[] = $path;
            }

            if (! $isLeaf) {
                array_push($rows, ...self::rows($child, $path));
            }
        }

        return $rows;
    }

    private static function sectionOf(string $path): string
    {
        $best = '';

        foreach (self::SECTIONS as $section) {
            if ($section !== '' && str_starts_with($path, $section.'.') && strlen($section) > strlen($best)) {
                $best = $section;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $problems
     */
    private static function walk(array $node, mixed $value, string $at, array &$problems): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $path = $at === '' ? (string) $key : "$at.$key";
            $next = $node[$key] ?? $node['*'] ?? null;

            if ($next === null) {
                $problems[] = "$path: unknown key".self::suggestion((string) $key, $node);

                continue;
            }

            if (is_array($next)) {
                self::walk($next, $child, $path, $problems);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function suggestion(string $key, array $node): string
    {
        $best = null;
        $distance = 3;

        foreach (array_keys($node) as $known) {
            $d = levenshtein($key, (string) $known);

            if ($d < $distance) {
                [$best, $distance] = [$known, $d];
            }
        }

        return $best === null ? '.' : "; did you mean [$best]?";
    }
}
