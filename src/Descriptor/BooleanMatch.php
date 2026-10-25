<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * How several checked boxes of one boolean filter combine.
 */
enum BooleanMatch: string
{
    case Any = 'any';
    case All = 'all';
}
