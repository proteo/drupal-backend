<?php

namespace Proteo\DrupalBackend\Tests\Fixtures;

use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\EntityDecorator;

/**
 * Adds an average `rating` per talk. A real one would read the ratings from a store in one query.
 */
final class TalkDecorator implements EntityDecorator
{
    /**
     * @param  list<Entity>  $entities
     * @return array<string, array{rating: float}>
     */
    public function decorate(array $entities): array
    {
        return collect($entities)->mapWithKeys(fn (Entity $entity): array => [$entity->id => ['rating' => 4.5]])->all();
    }
}
