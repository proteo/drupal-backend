<?php

namespace Proteo\DrupalBackend\Descriptor;

enum FieldType: string
{
    case String = 'string';
    case Html = 'html';
    case Date = 'date';
    case Excerpt = 'excerpt';
    case Integer = 'integer';
    case Boolean = 'boolean';
}
