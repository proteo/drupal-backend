<?php

namespace Proteo\DrupalBackend\Validation;

/**
 * A live check added by a package, run by `drupal:validate` once per Source
 * after the built-in ones. Tag the service `drupal-backend.checks`. A check
 * decides whether it applies to the Source it is given; one that throws is
 * reported as a failed result, not a crash.
 */
interface BackendCheck
{
    /**
     * @return list<CheckResult>
     */
    public function run(CheckContext $context): array;
}
