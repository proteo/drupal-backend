<?php

namespace Proteo\DrupalBackend\Contract;

use Proteo\DrupalBackend\Descriptor\ContentType;

/**
 * Adds requirements to the output of `drupal:contract`. Tag the service
 * `drupal-backend.contract`.
 */
interface ContractContributor
{
    /**
     * Lines for "Required modules", each without the checkbox, e.g. `Search API`.
     *
     * @return list<string>
     */
    public function modules(): array;

    /**
     * Lines for a content type's section, each a complete Markdown line.
     *
     * @return list<string>
     */
    public function forContentType(ContentType $type): array;

    /**
     * Whole extra sections, each Markdown starting with its own heading.
     *
     * @return list<string>
     */
    public function sections(): array;
}
