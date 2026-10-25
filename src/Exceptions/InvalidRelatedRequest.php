<?php

namespace Proteo\DrupalBackend\Exceptions;

use LogicException;

/**
 * A related content request that cannot be answered however the backend
 * behaves: a configuration error, so it is not a {@see DrupalException} and
 * is never swallowed.
 */
final class InvalidRelatedRequest extends LogicException {}
