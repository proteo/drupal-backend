<?php

namespace Proteo\DrupalBackend\Tests\Support;

use Proteo\DrupalBackend\Support\PaginationLinks;
use Proteo\DrupalBackend\Tests\TestCase;

class PaginationLinksTest extends TestCase
{
    public function test_the_first_page_with_more_results_only_has_a_next_link(): void
    {
        $links = PaginationLinks::build(1, true, 'talks');

        $this->assertSame(['next' => url('talks?page=2')], $links);
    }

    public function test_page_two_links_back_to_the_clean_url(): void
    {
        $links = PaginationLinks::build(2, false, '/talks');

        $this->assertSame(['prev' => url('talks')], $links);
    }

    public function test_later_pages_link_to_the_previous_page_number(): void
    {
        $links = PaginationLinks::build(3, true, 'talks');

        $this->assertSame(url('talks?page=2'), $links['prev']);
        $this->assertSame(url('talks?page=4'), $links['next']);
    }

    public function test_the_query_is_kept_and_its_page_is_replaced(): void
    {
        $links = PaginationLinks::build(2, true, 'search', ['q' => 'fanon', 'page' => 9]);

        $this->assertSame(url('search?q=fanon'), $links['prev']);
        $this->assertSame(url('search?q=fanon&page=3'), $links['next']);
    }

    public function test_a_nonsense_page_is_treated_as_the_first(): void
    {
        $this->assertSame([], PaginationLinks::build(-4, false, 'talks'));
    }
}
