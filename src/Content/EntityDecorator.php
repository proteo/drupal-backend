<?php

namespace Proteo\DrupalBackend\Content;

/**
 * Computes extra values for a batch of entities of one content type.
 *
 * Named by `decorator` in the descriptor and resolved from the container. It
 * runs once per type per response, with every entity of that type the
 * response mapped, nested ones included. The package caches nothing: a
 * decorator that calls a remote service caches it itself.
 */
interface EntityDecorator
{
    /**
     * @param  list<Entity>  $entities
     * @return array<string, array<string, mixed>> Extras by entity id; an id left out has none.
     */
    public function decorate(array $entities): array;
}
