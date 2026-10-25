<?php

namespace Proteo\DrupalBackend\Exceptions;

/**
 * Connection error, timeout, 5xx after retries, or throttling by the backend.
 */
final class DrupalUnavailable extends DrupalException
{
    public function status(): int
    {
        return 503;
    }

    protected function publicMessage(): string
    {
        return 'The content service is temporarily unavailable.';
    }

    protected function headers(): array
    {
        return ['Retry-After' => '30'];
    }
}
