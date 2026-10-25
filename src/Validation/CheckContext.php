<?php

namespace Proteo\DrupalBackend\Validation;

use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\Source;

/**
 * What a {@see BackendCheck} is given for one Source.
 */
final readonly class CheckContext
{
    /**
     * @param  ?string  $only  The Source `drupal:validate` was limited to, if any.
     */
    public function __construct(
        public Descriptor $descriptor,
        public Source $source,
        public DrupalClient $client,
        public ?string $only = null,
    ) {}
}
