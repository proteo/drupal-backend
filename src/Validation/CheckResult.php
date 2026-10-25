<?php

namespace Proteo\DrupalBackend\Validation;

/**
 * The outcome of one live check.
 */
final readonly class CheckResult
{
    /**
     * @param  string  $scope  Content type key, or `source:<name>` for checks about a whole Source.
     * @param  ?string  $hint  How to fix it; names the matching section of `drupal:contract`.
     */
    public function __construct(
        public CheckStatus $status,
        public string $scope,
        public string $check,
        public string $expected,
        public string $found,
        public ?string $hint = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'scope' => $this->scope,
            'check' => $this->check,
            'expected' => $this->expected,
            'found' => $this->found,
            'hint' => $this->hint,
        ];
    }
}
