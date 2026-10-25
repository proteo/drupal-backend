<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * How the system treats a content type; the set is closed.
 */
enum Role: string
{
    case Primary = 'primary';
    case Facet = 'facet';
    case Page = 'page';
    case Reference = 'reference';
}
