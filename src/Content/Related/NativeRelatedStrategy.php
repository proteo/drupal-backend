<?php

namespace Proteo\DrupalBackend\Content\Related;

use Illuminate\Support\Collection;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Client\CollectionRequest;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;

/**
 * Relates through the native collections: one request per target type and
 * criterion, because an OR group over relationships drops items that have no
 * value in one of them. Each candidate is scored from its own relationship
 * linkage, so it counts every criterion it shares even when only one request
 * returned it.
 *
 * Each request is cut at `search.native_limit`; a pool larger than that is
 * ranked from its first page only.
 */
class NativeRelatedStrategy implements RelatedStrategy
{
    public function __construct(
        private readonly DrupalManager $drupal,
        private readonly Descriptor $descriptor,
        private readonly ContentMapper $content,
    ) {}

    public function related(RelatedRequest $request): Collection
    {
        $source = $this->descriptor->contentType($request->source->type);
        $wanted = [];

        foreach ($request->criteria as $position => $criterion) {
            $wanted[$position] = $this->ids($request->source, $criterion->relation);
        }

        $sends = [];

        foreach ($request->types as $typeKey) {
            $type = $this->descriptor->contentType($typeKey);

            foreach ($request->criteria as $position => $criterion) {
                if ($wanted[$position] !== []) {
                    $sends[] = ['type' => $type, 'request' => $this->query($type, $criterion->relationFor($typeKey), $wanted[$position], $request)];
                }
            }
        }

        if ($sends === []) {
            return collect();
        }

        $responses = $this->drupal->source($source->source)->search(array_column($sends, 'request'));
        $candidates = [];

        foreach ($sends as $position => ['type' => $type]) {
            $document = $responses[$position]->document;

            foreach ($responses[$position]->data() as $item) {
                if ($item['id'] !== $request->source->id && $type->isVisible($item)) {
                    $candidates[$item['id']] ??= ['type' => $type, 'item' => $item, 'document' => $document];
                }
            }
        }

        foreach ($candidates as $id => $candidate) {
            $candidates[$id]['scores'] = array_map(
                fn (Criterion $criterion, array $ids): int => count(array_intersect($this->linked($candidate['item'], $candidate['type'], $criterion), $ids)),
                $request->criteria,
                $wanted,
            );
        }

        $typeOrder = array_flip($request->types);
        uasort($candidates, fn (array $a, array $b): int => $b['scores'] <=> $a['scores']
            ?: $this->byOrder($a['item'], $b['item'], $request->order)
            ?: $typeOrder[$a['type']->key] <=> $typeOrder[$b['type']->key]
            ?: strcmp($a['item']['id'], $b['item']['id']));

        $entities = collect(array_slice($candidates, 0, $request->limit))
            ->map(fn (array $candidate): Entity => $this->content->map($candidate['type']->key, $candidate['item'], $candidate['document']))
            ->values();

        foreach (collect($candidates)->take($request->limit)->pluck('document')->unique(fn (JsonApiDocument $document): string => spl_object_id($document)) as $document) {
            $this->content->decorate($document);
        }

        return $entities;
    }

    /**
     * @param  list<string>  $ids
     */
    private function query(ContentType $type, string $relation, array $ids, RelatedRequest $request): CollectionRequest
    {
        $via = $type->relations[$relation]->via;
        $query = $type->conditionQuery() + [
            'filter[related][condition][path]' => "$via.id",
            'filter[related][condition][operator]' => 'IN',
            'filter[related][condition][value]' => $ids,
        ];

        if (($include = $type->include('listing')) !== '') {
            $query['include'] = $include;
        }

        if (($sort = $request->order ?? $type->defaultSort) !== null) {
            $query['sort'] = $sort;
        }

        return new CollectionRequest($type->nativeCollection, $query, $this->descriptor->search->nativeLimit);
    }

    /**
     * The ids of the entries the entity holds in a relation. A criterion's relation
     * must be loaded wherever the source is, so an unloaded one counts as none.
     *
     * @return list<string>
     */
    private function ids(Entity $entity, string $relation): array
    {
        $value = $entity->get($relation);

        return array_values(array_map(
            fn (Entity $related): string => $related->id,
            array_filter(is_array($value) ? $value : [$value], fn (mixed $related): bool => $related instanceof Entity),
        ));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function linked(array $item, ContentType $type, Criterion $criterion): array
    {
        $data = $item['relationships'][$type->relations[$criterion->relationFor($type->key)]->via]['data'] ?? [];
        $data = isset($data['id']) ? [$data] : $data;

        return array_values(array_filter(array_column(is_array($data) ? $data : [], 'id')));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function byOrder(array $a, array $b, ?string $order): int
    {
        if ($order === null) {
            return 0;
        }

        $attribute = ltrim($order, '-');
        $result = ($a['attributes'][$attribute] ?? null) <=> ($b['attributes'][$attribute] ?? null);

        return str_starts_with($order, '-') ? -$result : $result;
    }
}
