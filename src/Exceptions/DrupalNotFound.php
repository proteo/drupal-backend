<?php

namespace Proteo\DrupalBackend\Exceptions;

/**
 * The resource does not exist, is restricted (403 is deliberately the same
 * outcome), or is not of the type the caller asked for.
 */
final class DrupalNotFound extends DrupalException
{
    public function status(): int
    {
        return 404;
    }

    protected function publicMessage(): string
    {
        return 'Not found.';
    }
}
