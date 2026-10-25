<?php

namespace Proteo\DrupalBackend\Content;

/**
 * Implemented by an Entity subclass that builds its own page metadata,
 * taking over from the descriptor's `seo` map.
 */
interface ProvidesSeo
{
    /**
     * Normally a `RalphJSmit\Laravel\SEO\Support\SEOData`; typed loosely because that package is only suggested.
     */
    public function seo(): object;
}
