<?php

namespace Proteo\DrupalBackend\Descriptor;

/**
 * A rule a content type's entities must satisfy to appear in lists, search and
 * suggestions. It does not hide the item page.
 */
final readonly class Condition
{
    public const array OPERATORS = ['=', '<>', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];

    public const array LISTS = ['IN', 'NOT IN'];

    public const array NULLS = ['IS NULL', 'IS NOT NULL'];

    /**
     * @param  string  $attribute  Native field, read from the resource's attributes.
     * @param  scalar|list<scalar>|null  $value
     * @param  bool  $filterable  Send the native rule upstream instead of testing it in PHP.
     */
    public function __construct(
        public string $attribute,
        public string $operator,
        public mixed $value = null,
        public bool $filterable = false,
    ) {}

    /**
     * Whether a native resource's attributes satisfy the rule; a rule without an attribute cannot be tested here.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function matches(array $attributes): bool
    {
        if ($this->attribute === null) {
            return true;
        }

        $actual = $attributes[$this->attribute] ?? null;

        return match ($this->operator) {
            '=' => $this->equal($actual, $this->value),
            '<>' => ! $this->equal($actual, $this->value),
            '>' => is_numeric($actual) && $actual > $this->value,
            '>=' => is_numeric($actual) && $actual >= $this->value,
            '<' => is_numeric($actual) && $actual < $this->value,
            '<=' => is_numeric($actual) && $actual <= $this->value,
            'IN' => $this->among($actual),
            'NOT IN' => ! $this->among($actual),
            'IS NULL' => $actual === null,
            'IS NOT NULL' => $actual !== null,
        };
    }

    /**
     * The JSON:API filter that sends the rule upstream for a field.
     *
     * @return array<string, mixed>
     */
    public function query(string $field): array
    {
        $query = [
            "filter[$field][condition][path]" => $field,
            "filter[$field][condition][operator]" => $this->operator,
        ];

        if (! in_array($this->operator, self::NULLS, true)) {
            $query["filter[$field][condition][value]"] = is_array($this->value)
                ? array_map($this->upstream(...), $this->value)
                : $this->upstream($this->value);
        }

        return $query;
    }

    private function equal(mixed $actual, mixed $expected): bool
    {
        return is_bool($expected) || is_bool($actual) ? (bool) $actual === (bool) $expected : $actual == $expected;
    }

    private function among(mixed $actual): bool
    {
        foreach ((array) $this->value as $candidate) {
            if ($this->equal($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** Booleans go upstream as 1 and 0. */
    private function upstream(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }
}
