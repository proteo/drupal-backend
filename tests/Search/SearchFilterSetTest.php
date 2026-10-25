<?php

namespace Proteo\DrupalBackend\Tests\Search;

use LogicException;
use Proteo\DrupalBackend\Search\SearchFilterSet;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class SearchFilterSetTest extends DescriptorTestCase
{
    private const string UUID = 'e34b1bd7-3e4a-4fd4-95e3-1eb97741f0ce';

    private function set(string $fixture = 'conference'): SearchFilterSet
    {
        return new SearchFilterSet($this->descriptor($this->fixture($fixture)));
    }

    public function test_parameters_and_defaults_come_from_the_filters(): void
    {
        $set = $this->set();

        $this->assertSame(['topic', 'speaker', 'track', 'year', 'slides', 'recording', 'level'], $set->parameters());
        $this->assertSame(['topic' => '', 'speaker' => '', 'track' => '', 'year' => '', 'slides' => false, 'recording' => false, 'level' => ''], $set->defaults());
    }

    public function test_valid_values_are_typed(): void
    {
        $filters = $this->set()->validate(['topic' => self::UUID, 'year' => '2024', 'slides' => 1, 'recording' => 'false']);

        $this->assertSame(['topic' => self::UUID, 'year' => 2024, 'slides' => true], $filters->values);
        $this->assertSame([], $filters->problems);
        $this->assertSame(3, $filters->count());
    }

    public function test_invalid_values_are_dropped_and_reported(): void
    {
        $filters = $this->set()->validate(['topic' => 'not-a-uuid', 'track' => ['x'], 'year' => '1999']);

        $this->assertSame([], $filters->values);
        $this->assertSame(['Ignored a topic filter that is not valid.', 'Ignored a year that is not valid.'], $filters->problems);
        $this->assertFalse($filters->isActive());
    }

    public function test_a_year_after_the_current_one_is_invalid(): void
    {
        $filters = $this->set()->validate(['year' => (string) (now()->year + 1)]);

        $this->assertSame([], $filters->values);
        $this->assertCount(1, $filters->problems);
    }

    public function test_years_run_from_the_current_one_down_to_the_first(): void
    {
        $set = $this->set();
        $years = $set->years($set->filters()['year']);

        $this->assertSame(now()->year, $years[0]);
        $this->assertSame(2021, end($years));
    }

    public function test_choices_come_from_the_relation_target(): void
    {
        $set = $this->set();

        $this->assertSame('topic', $set->optionsOf($set->filters()['topic']));
        $this->assertSame('track', $set->optionsOf($set->filters()['track']));
    }

    public function test_a_filter_without_a_content_relation_has_no_choices(): void
    {
        $set = $this->set();

        $this->expectException(LogicException::class);
        $set->optionsOf($set->filters()['year']);
    }

    public function test_labels_default_to_the_key(): void
    {
        $set = $this->set();

        $this->assertSame('Topic', $set->label($set->filters()['topic']));
    }

    public function test_another_descriptor_yields_its_own_parameters(): void
    {
        $set = $this->set('article');

        $this->assertSame(['topic', 'genre'], $set->parameters());
        $this->assertSame(['topic' => '', 'genre' => ''], $set->defaults());
        $this->assertFalse($set->validate(['speaker' => self::UUID])->isActive());
        $this->assertSame(['genre' => self::UUID], $set->validate(['genre' => self::UUID])->values);
    }
}
