<?php

namespace Proteo\DrupalBackend\Search;

use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Client\CollectionRequest;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\Relation;
use Proteo\DrupalBackend\Exceptions\DrupalException;

/**
 * Suggestions for the simple search field: matches of a typed term on each
 * searched type's native collection, held to per-type quotas.
 *
 * Goes to the client directly: suggestions are never cached. They fail
 * silently because they are a convenience; the visitor can still submit the form.
 */
class SuggestionFetcher
{
    public function __construct(
        private readonly DrupalManager $drupal,
        private readonly Descriptor $descriptor,
    ) {}

    /**
     * @return list<Suggestion> At most the configured limit, in tab order.
     */
    public function suggest(string $term): array
    {
        $config = $this->descriptor->search;
        $term = mb_substr(trim($term), 0, $config->termMax);

        if (! $config->enabled || mb_strlen($term) < $config->termMin) {
            return [];
        }

        $types = array_map(fn (string $key): ContentType => $this->descriptor->contentType($key), $config->tabs);
        $client = $this->drupal->client($this->descriptor->source($this->descriptor->defaultSource));

        try {
            $responses = $client->pool(array_map(fn (ContentType $type): CollectionRequest => $this->request($type, $term), $types));
        } catch (DrupalException $e) {
            report($e);

            return [];
        }

        $found = [];

        foreach ($types as $position => $type) {
            $response = $responses[$position];

            foreach ($response->data() as $item) {
                $suggestion = $this->suggestion($type, $item, $response->document);

                if ($suggestion !== null) {
                    $found[$type->key][] = $suggestion;
                }
            }
        }

        return $this->limited($found);
    }

    private function request(ContentType $type, string $term): CollectionRequest
    {
        $field = $type->titleAttribute();
        $fields = [$field, 'path'];

        // A plain CONTAINS on the native collection: its filters are not Lucene, so no sanitising.
        $query = [
            "filter[$field][operator]" => 'CONTAINS',
            "filter[$field][value]" => $term,
            'sort' => $field,
        ];

        $subtitle = $this->subtitleRelation($type);

        if ($subtitle !== null) {
            $fields[] = $subtitle->via;
            $query['include'] = $subtitle->via;
            $target = $this->descriptor->contentType((string) $subtitle->to);
            $query['fields['.$target->resourceType().']'] = $target->titleAttribute();
        }

        // Conditions are tested on the entry itself, so their attributes must come back.
        array_push($fields, ...$type->conditionAttributes());
        $query += $type->conditionQuery();

        $query['fields['.$type->resourceType().']'] = implode(',', $fields);

        return new CollectionRequest($type->nativeCollection, $query, $this->descriptor->search->suggestionLimit);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function suggestion(ContentType $type, array $item, JsonApiDocument $document): ?Suggestion
    {
        $title = $item['attributes'][$type->titleAttribute()] ?? null;
        $path = $item['attributes']['path'] ?? null;

        if (! is_string($title) || $title === '' || ! is_string($path) || $path === '') {
            return null;
        }

        if (! $type->isVisible($item)) {
            return null;
        }

        $subtitle = '';

        if (($relation = $this->subtitleRelation($type)) !== null) {
            $name = $document->related($item, $relation->via)[0]['attributes'][$this->descriptor->contentType((string) $relation->to)->titleAttribute()] ?? '';
            $subtitle = is_string($name) ? html_entity_decode($name) : '';
        }

        return new Suggestion($type->key, html_entity_decode($title), $type->url($path), $subtitle);
    }

    private function subtitleRelation(ContentType $type): ?Relation
    {
        return $type->suggestSubtitle === null ? null : ($type->relations[$type->suggestSubtitle] ?? null);
    }

    /**
     * Declared quotas as they are; the places they leave are split evenly
     * between the tabs without one, the first of them taking the odd places.
     *
     * @return array<string, int>
     */
    private function quotas(): array
    {
        $config = $this->descriptor->search;
        $quotas = array_intersect_key($config->suggestionQuotas, array_flip($config->tabs));
        $open = array_values(array_diff($config->tabs, array_keys($quotas)));

        if ($open !== []) {
            $left = max(0, $config->suggestionLimit - array_sum($quotas));

            foreach ($open as $position => $key) {
                $quotas[$key] = intdiv($left, count($open)) + ($position < $left % count($open) ? 1 : 0);
            }
        }

        return $quotas;
    }

    /**
     * At most the configured limit. Each type is held to its quota and unused
     * places go to whichever types have more matches, in tab order.
     *
     * @param  array<string, list<Suggestion>>  $found
     * @return list<Suggestion>
     */
    private function limited(array $found): array
    {
        $config = $this->descriptor->search;
        $chosen = [];
        $rest = [];

        $quotas = $this->quotas();

        foreach ($config->tabs as $key) {
            $items = $found[$key] ?? [];
            $quota = $quotas[$key];
            array_push($chosen, ...array_slice($items, 0, $quota));
            array_push($rest, ...array_slice($items, $quota));
        }

        $chosen = array_merge($chosen, array_slice($rest, 0, max(0, $config->suggestionLimit - count($chosen))));

        return array_slice($chosen, 0, $config->suggestionLimit);
    }
}
