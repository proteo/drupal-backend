<?php

namespace Proteo\DrupalBackend\Search;

use Illuminate\Support\Collection;
use Proteo\DrupalBackend\Client\DrupalResponse;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\Listing;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\SearchConfig;

/**
 * Answers a tab from the type's native collection, which matches partial
 * words ("class" finds "Classics"). A typed term goes in one request for the
 * type's own fields and one per related path, merged and scored here; without
 * a term the filters alone are paged by the collection's own offset.
 */
class NativeSearchBackend implements SearchBackend
{
    private readonly SearchConfig $config;

    public function __construct(
        private readonly Descriptor $descriptor,
        private readonly ContentMapper $content,
        private readonly NativeQueries $queries,
    ) {
        $this->config = $descriptor->search;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities;
    }

    public function plan(TabSearch $search): TabPlan
    {
        $term = $search->term;

        return $term->isUsable() && $term->terms !== [] ? $this->termPlan($search) : $this->filterPlan($search);
    }

    /**
     * No term: the filters alone, paged by the collection's own offset. The
     * count is what was seen, a floor while `links.next` says there is more.
     */
    private function filterPlan(TabSearch $search): TabPlan
    {
        $sort = $this->sortParam($search->type, $search->sort);
        $request = $this->queries->native(
            $search->type, $search->term, $search->filters, $search->active, $search->active ? $sort : null,
            limit: $search->active ? $this->config->perPage : null,
            offset: $search->active ? $search->offset : 0,
        );

        return new TabPlan([$request], function (array $responses) use ($search): TabResult {
            $key = $search->type->key;
            $matches = $this->visible($key, $responses[0]);
            $hasMore = $responses[0]->hasMore;
            $offset = $search->active ? $search->offset : 0;

            return new TabResult(
                $offset + count($matches) + ($hasMore ? 1 : 0),
                exact: ! $hasMore,
                listing: $search->active ? new Listing($this->mapItems($key, $matches, $responses[0]->document), $hasMore) : null,
            );
        });
    }

    /**
     * A typed term: the own-field request and one per related path, merged and
     * scored here.
     */
    private function termPlan(TabSearch $search): TabPlan
    {
        [$type, $term, $filters] = [$search->type, $search->term, $search->filters];
        $sort = $this->sortParam($type, $search->sort);
        $requests = [$this->queries->native($type, $term, $filters, $search->active, $search->active ? $sort : null)];

        foreach (array_keys($type->relatedTermFields) as $path) {
            $requests[] = $this->queries->native($type, $term, $filters, $search->active, null, $path);
        }

        $nativeSort = $requests[0]->query['sort'] ?? null;

        return new TabPlan($requests, function (array $responses) use ($search, $nativeSort): TabResult {
            $key = $search->type->key;
            $merged = $this->mergeNative($search->type, $responses, $search->term, $nativeSort, $search->sort === 'relevance');
            $matches = $this->visible($key, $merged);
            $capped = array_any($responses, fn (DrupalResponse $response): bool => $response->hasMore);

            return new TabResult(
                count($matches),
                exact: ! $capped,
                listing: $search->active ? $this->nativeListing($key, $matches, $search->offset, $merged->document) : null,
            );
        });
    }

    /**
     * An order that does not fit the type is dropped rather than sent
     * upstream. The native collection has no relevance, so it then falls back
     * to the type's first declared sort, or its title: the closest useful order.
     */
    private function sortParam(ContentType $type, string $sort): ?string
    {
        $param = match ($sort) {
            'relevance' => null,
            'title' => $type->sorts['title'] ?? $type->titleAttribute(),
            default => $type->sorts[$sort] ?? null,
        };

        return $param ?? ($sort === 'relevance' ? (array_values($type->sorts)[0] ?? $type->titleAttribute()) : null);
    }

    /**
     * Related names are matched by their own requests, so the responses are
     * merged here: an item matched through several paths scores for each.
     *
     * @param  non-empty-list<DrupalResponse>  $responses  The own-field matches first, then one per related path.
     */
    private function mergeNative(ContentType $type, array $responses, SanitisedTerm $term, ?string $sort, bool $byRelevance): DrupalResponse
    {
        $items = [];
        $scores = [];
        $weights = [0, ...array_values($type->relatedTermFields)];

        foreach ($responses as $position => $response) {
            foreach ($response->data() as $item) {
                $items[$item['id']] ??= $item;
                $scores[$item['id']] = ($scores[$item['id']] ?? 0) + $weights[$position];
            }
        }

        foreach ($items as $id => $item) {
            $scores[$id] += $this->textScore($type, $item, $term);
        }

        if ($sort !== null) {
            $field = ltrim($sort, '-');
            $direction = str_starts_with($sort, '-') ? -1 : 1;
            uasort($items, fn (array $a, array $b): int => ($byRelevance ? $scores[$b['id']] <=> $scores[$a['id']] : 0)
                ?: $direction * strcasecmp((string) ($a['attributes'][$field] ?? ''), (string) ($b['attributes'][$field] ?? '')));
        }

        return new DrupalResponse([
            'data' => array_values($items),
            'included' => array_merge(...array_map(fn (DrupalResponse $response): array => $response->document->payload['included'] ?? [], $responses)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function textScore(ContentType $type, array $item, SanitisedTerm $term): int
    {
        $score = 0;

        foreach ($type->termFields as $path => $weight) {
            $text = mb_strtolower($this->text($item['attributes'] ?? [], $path));

            foreach ($term->terms as $word) {
                $score += str_contains($text, mb_strtolower($word)) ? $weight : 0;
            }
        }

        return $score;
    }

    /**
     * A text attribute such as `description.value` may arrive as a plain string.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function text(array $attributes, string $path): string
    {
        $value = data_get($attributes, $path) ?? (str_ends_with($path, '.value') ? data_get($attributes, substr($path, 0, -6)) : null);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Conditions that are not sent upstream are tested here.
     *
     * @return list<array<string, mixed>>
     */
    private function visible(string $key, DrupalResponse $response): array
    {
        $type = $this->descriptor->contentType($key);

        return array_values(array_filter($response->data(), fn (array $item): bool => $type->isVisible($item)));
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return Listing<Entity>
     */
    private function nativeListing(string $key, array $matches, int $offset, JsonApiDocument $document): Listing
    {
        $page = array_slice($matches, $offset, $this->config->perPage);

        return new Listing(
            $this->mapItems($key, $page, $document),
            $offset + count($page) < count($matches),
            count($matches),
        );
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return Collection<int, Entity>
     */
    private function mapItems(string $key, array $data, JsonApiDocument $document): Collection
    {
        $entities = collect($data)->map(fn (array $item): Entity => $this->content->map($key, $item, $document))->values();
        $this->content->decorate($document);

        return $entities;
    }
}
