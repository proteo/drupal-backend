<?php

namespace Proteo\DrupalBackend\Content\Related;

use Illuminate\Support\Collection;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Exceptions\DrupalException;

/**
 * Finds content similar to an entity. Bind another implementation in the
 * container to replace the default, which works on the native collections.
 *
 * The request is already validated against the descriptor by
 * {@see RelatedContent}. A transport failure throws a
 * {@see DrupalException}, which the service turns into an empty list.
 */
interface RelatedStrategy
{
    /**
     * @return Collection<int, Entity> Mixed types, best match first, at most `$request->limit`.
     *
     * @throws DrupalException
     */
    public function related(RelatedRequest $request): Collection;
}
