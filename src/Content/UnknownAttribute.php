<?php

namespace Proteo\DrupalBackend\Content;

use OutOfBoundsException;

/**
 * A view asked an entity for a name its content type does not declare. Failing
 * loudly keeps a typo from rendering as an empty string.
 */
class UnknownAttribute extends OutOfBoundsException
{
    public static function named(string $name, string $contentType): self
    {
        return new self("Content type [$contentType] has no field or relation named [$name].");
    }
}
