<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * Content relations point at another content type; image and file relations
 * are value relations to resources that are never routed.
 */
enum RelationKind: string
{
    case Content = 'content';
    case Image = 'image';
    case File = 'file';
}
