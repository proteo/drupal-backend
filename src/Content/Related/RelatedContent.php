<?php

namespace Proteo\DrupalBackend\Content\Related;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\RelationKind;
use Proteo\DrupalBackend\Exceptions\DrupalException;
use Proteo\DrupalBackend\Exceptions\InvalidRelatedRequest;

/**
 * The entry point for related content. A request the descriptor cannot
 * satisfy throws {@see InvalidRelatedRequest}; a backend failure yields an
 * empty list, since related content is optional decoration.
 */
class RelatedContent
{
    public function __construct(
        private readonly RelatedStrategy $strategy,
        private readonly Descriptor $descriptor,
    ) {}

    /**
     * @return Collection<int, Entity>
     *
     * @throws InvalidRelatedRequest
     */
    public function find(RelatedRequest $request): Collection
    {
        $this->assertValid($request);

        try {
            return $this->strategy->related($request);
        } catch (DrupalException $e) {
            Log::warning('Related content fetch failed.', ['type' => $request->source->type, 'id' => $request->source->id, 'exception' => $e::class]);

            return collect();
        }
    }

    /**
     * @throws InvalidRelatedRequest
     */
    private function assertValid(RelatedRequest $request): void
    {
        if ($request->criteria === []) {
            throw new InvalidRelatedRequest('A related request needs at least one criterion.');
        }

        if ($request->limit < 1) {
            throw new InvalidRelatedRequest("The limit of a related request must be at least 1, [$request->limit] given.");
        }

        $max = (int) config('drupal.related.max_requests', 12);
        $count = count($request->types) * count($request->criteria);

        if ($count > $max) {
            throw new InvalidRelatedRequest("A related request sends one request per type and criterion: [$count] exceeds drupal.related.max_requests [$max].");
        }

        $source = $this->type($request->source->type);
        $targets = array_map(fn (string $key): ContentType => $this->type($key), $request->types);

        foreach (array_unique(array_map(fn (ContentType $type): string => $type->source, [$source, ...$targets])) as $name) {
            if ($name !== $source->source) {
                throw new InvalidRelatedRequest('Related content cannot span Sources: all types must come from the Source of the entity.');
            }
        }

        foreach ($request->criteria as $criterion) {
            $to = $this->targetOf($source, $criterion->relation);

            foreach ($targets as $target) {
                if ($this->targetOf($target, $criterion->relationFor($target->key)) !== $to) {
                    throw new InvalidRelatedRequest("Relation [{$criterion->relationFor($target->key)}] of [$target->key] does not point to the same kind of entity as [$criterion->relation] of [$source->key].");
                }
            }
        }

        if ($request->order !== null) {
            $attribute = ltrim($request->order, '-');

            foreach ($targets as $target) {
                if (! array_any($target->fields, fn ($field): bool => $field->source === $attribute)) {
                    throw new InvalidRelatedRequest("Order attribute [$attribute] is not a field of [$target->key].");
                }
            }
        }
    }

    private function type(string $key): ContentType
    {
        return $this->descriptor->contentTypes[$key] ?? throw new InvalidRelatedRequest("Unknown content type [$key].");
    }

    /**
     * The content type a relation points to; the relation must exist and be a content relation.
     */
    private function targetOf(ContentType $type, string $relation): string
    {
        $declared = $type->relations[$relation] ?? throw new InvalidRelatedRequest("Content type [$type->key] has no relation [$relation].");

        if ($declared->kind !== RelationKind::Content || $declared->to === null) {
            throw new InvalidRelatedRequest("Relation [$relation] of [$type->key] is not a content relation.");
        }

        return $declared->to;
    }
}
