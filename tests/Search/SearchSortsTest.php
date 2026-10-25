<?php

namespace Proteo\DrupalBackend\Tests\Search;

use Proteo\DrupalBackend\Search\SearchSorts;
use Proteo\DrupalBackend\Search\SearchTabs;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class SearchSortsTest extends DescriptorTestCase
{
    private function sorts(): SearchSorts
    {
        return new SearchSorts($this->descriptor($this->fixture('conference')));
    }

    public function test_the_primary_offers_relevance_its_declared_sorts_and_title(): void
    {
        $this->assertSame(
            ['relevance' => 'Relevance', 'newest' => 'Newest', 'oldest' => 'Oldest', 'title' => 'Title A to Z'],
            $this->sorts()->options('talk', true),
        );
    }

    public function test_relevance_needs_a_term(): void
    {
        $this->assertSame(['newest', 'oldest', 'title'], array_keys($this->sorts()->options('talk', false)));
    }

    public function test_a_facet_offers_relevance_and_title_only(): void
    {
        $this->assertSame(['relevance', 'title'], array_keys($this->sorts()->options('speaker', true)));
        $this->assertSame(['title'], array_keys($this->sorts()->options('speaker', false)));
    }

    public function test_an_order_that_does_not_fit_falls_back_to_the_tab_default(): void
    {
        $sorts = $this->sorts();

        $this->assertSame('oldest', $sorts->resolve('oldest', 'talk', true));
        $this->assertSame('relevance', $sorts->resolve('newest', 'speaker', true));
        $this->assertSame('title', $sorts->resolve('newest', 'speaker', false));
        $this->assertSame('newest', $sorts->resolve('relevance', 'talk', false));
    }

    public function test_an_unknown_name_is_treated_as_relevance(): void
    {
        $sorts = $this->sorts();

        $this->assertFalse($sorts->isKnown('price; DROP'));
        $this->assertSame('relevance', $sorts->resolve('price; DROP', 'talk', true));
        $this->assertSame('newest', $sorts->resolve('price; DROP', 'talk', false));
    }

    public function test_tabs_follow_the_descriptor(): void
    {
        $tabs = new SearchTabs($this->descriptor($this->fixture('conference')));

        $this->assertSame(['talk', 'workshop', 'topic', 'track'], $tabs->keys());
        $this->assertSame('talk', $tabs->resolve('nonsense'));
        $this->assertSame('track', $tabs->resolve('track'));
        $this->assertSame('Topics', $tabs->label('topic'));
    }
}
