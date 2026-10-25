<?php

namespace Proteo\DrupalBackend\View\Components;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\Related\Criterion;
use Proteo\DrupalBackend\Content\Related\RelatedContent;
use Proteo\DrupalBackend\Content\Related\RelatedRequest;
use Proteo\DrupalBackend\Exceptions\DrupalException;

/**
 * `<x-drupal::related :entity="$talk" :criteria="['topics', 'tracks']"/>`:
 * content that shares the entity's relations, the first criterion counting
 * most. It renders nothing when there is none or the backend fails; a request
 * the descriptor cannot satisfy is a configuration error and is not caught.
 *
 * A slot replaces the default markup and reads the results through
 * `$component->entities()`, because Blade cannot run a slot once per item.
 */
class Related extends Component
{
    /** @var Collection<int, Entity> */
    private Collection $entities;

    /**
     * @param  list<string|Criterion>  $criteria  Relation names in priority order.
     * @param  list<string>  $types  Content types to look in; the entity's own when empty.
     * @param  ?string  $order  Tie-break attribute, `-created` for newest first.
     */
    public function __construct(
        public Entity $entity,
        public array $criteria,
        public array $types = [],
        public int $limit = 10,
        public ?string $order = null,
        private readonly ?RelatedContent $related = null,
    ) {}

    public function shouldRender(): bool
    {
        $request = new RelatedRequest(
            $this->entity,
            array_map(fn (string|Criterion $criterion): Criterion => is_string($criterion) ? new Criterion($criterion) : $criterion, $this->criteria),
            $this->types,
            $this->limit,
            $this->order,
        );

        try {
            $this->entities = ($this->related ?? app(RelatedContent::class))->find($request);
        } catch (DrupalException $e) {
            report($e);

            return false;
        }

        return $this->entities->isNotEmpty();
    }

    /**
     * @return Collection<int, Entity> Best match first, mixed types.
     */
    public function entities(): Collection
    {
        return $this->entities;
    }

    public function render(): Closure
    {
        // A component closure may only return a view, so the slot passes through one.
        return fn (array $data): View => view(
            $data['slot']->isNotEmpty() ? 'drupal::components.related-slot' : 'drupal::components.related',
            ['entities' => $this->entities, 'entity' => $this->entity],
        );
    }
}
