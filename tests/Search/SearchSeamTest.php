<?php

namespace Proteo\DrupalBackend\Tests\Search;

use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Content\Listing;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Search\Capabilities;
use Proteo\DrupalBackend\Search\NativeSearchBackend;
use Proteo\DrupalBackend\Search\SanitisedTerm;
use Proteo\DrupalBackend\Search\SearchBackend;
use Proteo\DrupalBackend\Search\SearchFetcher;
use Proteo\DrupalBackend\Search\SearchResults;
use Proteo\DrupalBackend\Search\TabPlan;
use Proteo\DrupalBackend\Search\TabResult;
use Proteo\DrupalBackend\Search\TabSearch;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class SearchSeamTest extends DescriptorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('drupal-backend', $this->fixture('conference'));
    }

    public function test_the_native_backend_is_the_default_binding(): void
    {
        $this->assertInstanceOf(NativeSearchBackend::class, $this->app->make(SearchBackend::class));
    }

    public function test_a_plan_hands_its_responses_and_the_client_to_its_resolver(): void
    {
        $plan = new TabPlan([], fn (array $responses, $drupal): TabResult => new TabResult(count($responses), false));

        $result = $plan->resolve([], $this->app->make(CachedDrupal::class));

        $this->assertSame(0, $result->count);
        $this->assertFalse($result->exact);
        $this->assertNull($result->listing);
    }

    public function test_capabilities_default_to_nothing(): void
    {
        $capabilities = new Capabilities;

        $this->assertFalse($capabilities->exactCounts || $capabilities->indexOnlyBooleans || $capabilities->fulltext);
    }

    public function test_an_inexact_count_reads_as_a_floor(): void
    {
        $results = new SearchResults('a', ['a' => 50, 'b' => 3], new Listing(collect(), false), ['a' => false]);

        $this->assertSame('50+', $results->countLabel('a'));
        $this->assertSame('3', $results->countLabel('b'));
        $this->assertTrue($results->isExact('b'));
        $this->assertSame(53, $results->total());
    }

    public function test_the_fetcher_runs_whatever_backend_it_is_given(): void
    {
        $descriptor = $this->app->make(Descriptor::class);
        $backend = new class implements SearchBackend
        {
            public function plan(TabSearch $search): TabPlan
            {
                return new TabPlan([], fn (): TabResult => new TabResult(
                    $search->type->key === 'talk' ? 50 : 2,
                    exact: $search->type->key !== 'talk',
                    listing: $search->active ? new Listing(collect(), false) : null,
                ));
            }

            public function capabilities(): Capabilities
            {
                return new Capabilities;
            }
        };

        $results = (new SearchFetcher(new DrupalManager($descriptor), $descriptor, $backend))
            ->search(new SanitisedTerm('x', ['x']), 'talk', 1);

        $this->assertSame('50+', $results->countLabel('talk'));
        $this->assertSame('2', $results->countLabel('workshop'));
    }
}
