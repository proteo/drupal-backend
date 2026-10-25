<?php

namespace Proteo\DrupalBackend\Content;

/**
 * The extras of one response, shared by every entity mapped from it.
 *
 * Entities stay readonly: the mapper registers each one here, a decorator
 * pass fills the values later, and `Entity::extra()` reads them back.
 */
final class Extras
{
    /**
     * @var array<string, array<string, Entity>> Entities awaiting decoration, by type and id.
     */
    private array $pending = [];

    /**
     * @var array<string, array<string, array<string, mixed>>> Values by type, id and name.
     */
    private array $values = [];

    public function track(Entity $entity): void
    {
        $this->pending[$entity->type][$entity->id] ??= $entity;
    }

    /**
     * Hands over the entities not yet decorated, grouped by type.
     *
     * @return array<string, list<Entity>>
     */
    public function drain(): array
    {
        $pending = array_map('array_values', $this->pending);
        $this->pending = [];

        return $pending;
    }

    /**
     * @param  array<string, array<string, mixed>>  $extras  By entity id.
     */
    public function put(string $type, array $extras): void
    {
        foreach ($extras as $id => $values) {
            $this->values[$type][(string) $id] = $values + ($this->values[$type][(string) $id] ?? []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function for(string $type, string $id): array
    {
        return $this->values[$type][$id] ?? [];
    }
}
