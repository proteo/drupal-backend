<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * Closed on purpose: a new filter shape means new package code.
 */
enum FilterType: string
{
    case Equals = 'equals';
    case YearRange = 'year_range';
    case Boolean = 'boolean';
}
