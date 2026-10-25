<?php

namespace Proteo\DrupalBackend\Descriptor;

final readonly class WebhookConfig
{
    /**
     * @param  int  $tolerance  Seconds a signed timestamp stays valid; a Source may override it.
     */
    public function __construct(public string $path = '/hooks/drupal', public int $tolerance = 30) {}
}
