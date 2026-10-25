<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * One boolean field behind a `boolean` filter parameter.
 */
final readonly class BooleanField
{
    /**
     * @param  ?string  $native  Filter path on the native collection; without it only a backend with index-only booleans can answer the parameter.
     * @param  bool  $present  The path is not a boolean but a field or relationship: the box selects items where it has a value (`IS NOT NULL`) instead of where it equals `1`.
     */
    public function __construct(public ?string $native = null, public bool $present = false) {}
}
