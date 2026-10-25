<?php

namespace Proteo\DrupalBackend\Tests\Descriptor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Proteo\DrupalBackend\Descriptor\Condition;

class ConditionTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed, array<string, mixed>, bool}>
     */
    public static function cases(): array
    {
        return [
            'greater, passes' => ['>', 0, ['n' => '3'], true],
            'greater, fails' => ['>', 0, ['n' => '0'], false],
            'missing is not greater' => ['>', 0, [], false],
            'equal' => ['=', 'x', ['n' => 'x'], true],
            'not equal' => ['<>', 'x', ['n' => 'x'], false],
            'boolean equal' => ['=', true, ['n' => 1], true],
            'in' => ['IN', [1, 2], ['n' => 2], true],
            'not in' => ['NOT IN', [1, 2], ['n' => 2], false],
            'is null on missing' => ['IS NULL', null, [], true],
            'is not null on missing' => ['IS NOT NULL', null, [], false],
            'less or equal' => ['<=', 5, ['n' => 5], true],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('cases')]
    public function test_it_tests_a_native_resource(string $operator, mixed $value, array $attributes, bool $expected): void
    {
        $this->assertSame($expected, (new Condition('n', $operator, $value))->matches($attributes));
    }

    public function test_it_builds_the_upstream_filter_with_booleans_as_digits(): void
    {
        $this->assertSame([
            'filter[f][condition][path]' => 'f',
            'filter[f][condition][operator]' => '=',
            'filter[f][condition][value]' => 1,
        ], (new Condition('f', '=', true))->query('f'));

        $this->assertArrayNotHasKey('filter[f][condition][value]', (new Condition('f', 'IS NULL'))->query('f'));
        $this->assertSame([1, 2], (new Condition('f', 'IN', [1, 2]))->query('f')['filter[f][condition][value]']);
    }
}
