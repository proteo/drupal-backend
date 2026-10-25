<?php

namespace Proteo\DrupalBackend\Exceptions;

/**
 * The backend answered, but not with something usable.
 */
final class DrupalBadResponse extends DrupalException
{
    public function status(): int
    {
        return 502;
    }

    protected function publicMessage(): string
    {
        return 'The content service returned an invalid response.';
    }
}
