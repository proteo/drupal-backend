<?php

namespace Proteo\DrupalBackend\Descriptor;

use RuntimeException;

class InvalidDescriptor extends RuntimeException
{
    public static function because(string $path, string $reason): self
    {
        return new self("Invalid drupal-backend descriptor at [$path]: $reason");
    }

    /**
     * @param  list<string>  $problems
     */
    public static function withProblems(array $problems): self
    {
        return new self('The drupal-backend descriptor has '.count($problems)." problem(s):\n- ".implode("\n- ", $problems));
    }

    /**
     * @param  list<string>  $removed  One line per removed key, with its replacement.
     */
    public static function removedKeys(array $removed): self
    {
        return new self(
            'The drupal-backend descriptor uses keys removed by schema_version '.Descriptor::SCHEMA_VERSION.'; set schema_version to '.Descriptor::SCHEMA_VERSION.' and replace:'
            ."\n- ".implode("\n- ", $removed)
            ."\nSearch API index keys move to the proteo/drupal-search-api package (install it for index-backed search). See the Upgrading page for the full table.",
        );
    }

    public static function schemaMismatch(mixed $found): self
    {
        return new self(sprintf(
            'The drupal-backend descriptor declares schema_version [%s] but this package reads version %d. Update the descriptor to the current schema (see the package changelog).',
            is_scalar($found) ? (string) $found : gettype($found),
            Descriptor::SCHEMA_VERSION,
        ));
    }
}
