<?php

namespace Proteo\DrupalBackend\Tests\Content;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\DataProvider;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Client\JsonApiDocument;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Content\Related\Criterion;
use Proteo\DrupalBackend\Content\Related\NativeRelatedStrategy;
use Proteo\DrupalBackend\Content\Related\RelatedContent;
use Proteo\DrupalBackend\Content\Related\RelatedRequest;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Exceptions\InvalidRelatedRequest;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;

class RelatedTest extends DescriptorTestCase
{
    use FakesDrupal;

    private Descriptor $descriptor;

    private ContentMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.cache.enabled' => false, 'drupal.timeout' => 5, 'drupal.connect_timeout' => 2, 'drupal.retries' => 0, 'drupal.retry_delay_ms' => 0]);

        $this->descriptor = $this->descriptor($this->fixture('conference'));
        $this->mapper = new ContentMapper($this->descriptor, new EntityFactory($this->descriptor), 'https://app.test');
    }

    protected function drupalDescriptor(): Descriptor
    {
        return $this->descriptor;
    }

    private function service(): RelatedContent
    {
        return new RelatedContent(
            new NativeRelatedStrategy(new DrupalManager($this->descriptor), $this->descriptor, $this->mapper),
            $this->descriptor,
        );
    }

    /**
     * @param  list<string>  $tracks
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function talk(string $id, string $topic, array $tracks = [], string $speaker = 'a9', string $created = '2024-01-01T00:00:00+00:00'): array
    {
        return $this->fakeEntity('talk', ['id' => $id, 'date' => $created], [
            'topics' => [$this->fakeEntity('topic', ['id' => $topic])],
            'tracks' => array_map(fn (string $track): array => $this->fakeEntity('track', ['id' => $track]), $tracks),
            'speakers' => [$this->fakeEntity('speaker', ['id' => $speaker])],
        ]);
    }

    public function source(): Entity
    {
        $talk = $this->talk('self', 'c1', ['t1', 't2'], 'a1');

        return $this->mapper->map('talk', $talk[0], new JsonApiDocument($this->fakeItem($talk)));
    }

    /**
     * Every collection answers with the candidates of its own path, whatever the filter.
     *
     * @param  array<string, list<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>>  $byCollection
     */
    private function fakeCandidates(array $byCollection): void
    {
        Http::fake(function (Request $request) use ($byCollection) {
            $type = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->fakeCollection($type, $byCollection[$type] ?? []));
        });
    }

    /**
     * @param  list<string>  $criteria
     * @param  list<string>  $types
     */
    public function request(array $criteria = ['topics', 'tracks', 'speakers'], array $types = [], int $limit = 10, ?string $order = '-created', ?Entity $source = null): RelatedRequest
    {
        return new RelatedRequest($source ?? $this->source(), array_map(fn (string $name): Criterion => new Criterion($name), $criteria), $types, $limit, $order);
    }

    /**
     * @return list<string>
     */
    private function ids(?RelatedRequest $request = null): array
    {
        return $this->service()->find($request ?? $this->request())->map(fn (Entity $e): string => $e->id)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentQueries(): array
    {
        return Http::recorded()->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query + ['_path' => basename((string) parse_url($pair[0]->url(), PHP_URL_PATH))];
        })->all();
    }

    public function test_ranks_by_topic_then_tracks_then_speaker_and_skips_the_entity_itself(): void
    {
        $this->fakeCandidates(['talk' => [
            $this->talk('self', 'c1', ['t1', 't2'], 'a1'),
            $this->talk('speaker-only', 'c9', [], 'a1'),
            $this->talk('one-track', 'c9', ['t1']),
            $this->talk('topic', 'c1'),
            $this->talk('two-tracks', 'c9', ['t1', 't2']),
        ]]);

        $this->assertSame(['topic', 'two-tracks', 'one-track', 'speaker-only'], $this->ids());
    }

    public function test_a_candidate_returned_by_one_request_still_scores_every_criterion(): void
    {
        $this->fakeCandidates(['talk' => [$this->talk('both', 'c1', ['t1']), $this->talk('topic-only', 'c1')]]);

        $this->assertSame(['both', 'topic-only'], $this->ids($this->request(['topics', 'tracks'])));
    }

    public function test_ties_break_on_the_order_attribute(): void
    {
        $this->fakeCandidates(['talk' => [
            $this->talk('old', 'c1', created: '2020-01-01T00:00:00+00:00'),
            $this->talk('new', 'c1', created: '2024-06-01T00:00:00+00:00'),
            $this->talk('mid', 'c1', created: '2022-01-01T00:00:00+00:00'),
        ]]);

        $this->assertSame(['new', 'mid', 'old'], $this->ids());
        $this->assertSame(['old', 'mid', 'new'], $this->ids($this->request(order: 'created')));
    }

    public function test_limits_to_the_requested_amount(): void
    {
        $this->fakeCandidates(['talk' => array_map(fn (int $i): array => $this->talk("b{$i}", 'c1'), range(1, 12))]);

        $this->assertCount(10, $this->ids());
        $this->assertCount(3, $this->ids($this->request(limit: 3)));
    }

    public function test_sends_one_request_per_criterion_filtering_on_the_source_ids(): void
    {
        $this->fakeCandidates([]);

        $this->ids();

        $sent = $this->sentQueries();
        $this->assertCount(3, $sent);
        $this->assertSame(['topic.id', 'tracks.id', 'speaker.id'], array_map(fn (array $q): string => $q['filter']['related']['condition']['path'], $sent));
        $this->assertSame(['t1', 't2'], $sent[1]['filter']['related']['condition']['value']);
        $this->assertSame('IN', $sent[0]['filter']['related']['condition']['operator']);
        $this->assertSame('-created', $sent[0]['sort']);
        $this->assertSame('50', $sent[0]['page']['limit']);
    }

    public function test_the_type_default_sort_orders_the_request_without_an_order(): void
    {
        $this->fakeCandidates([]);

        $this->ids($this->request(order: null));

        $this->assertSame('-created', $this->sentQueries()[0]['sort']);
    }

    public function test_mixed_types_are_matched_through_their_own_relation_and_merged(): void
    {
        $workshop = $this->fakeEntity('workshop', ['id' => 'w1'], ['levels' => [$this->fakeEntity('topic', ['id' => 'c1'])]]);
        $this->fakeCandidates(['talk' => [$this->talk('t-topic', 'c1')], 'workshop' => [$workshop]]);
        $request = new RelatedRequest($this->source(), [new Criterion('topics', ['workshop' => 'levels'])], ['talk', 'workshop'], 10, null);

        $this->assertSame(['t-topic', 'w1'], $this->ids($request));
        $this->assertSame(['level.id', 'topic.id'], collect($this->sentQueries())->map(fn (array $q): string => $q['filter']['related']['condition']['path'])->sort()->values()->all());
    }

    public function test_conditions_of_the_type_are_sent_with_each_request(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['conditions'] = [['attribute' => 'status', 'operator' => '=', 'value' => true, 'filterable' => true]];
        $this->descriptor = $this->descriptor($config);
        $this->fakeCandidates([]);

        $this->ids();

        $this->assertSame('1', $this->sentQueries()[0]['filter']['status']['condition']['value']);
    }

    public function test_a_source_with_no_values_sends_nothing(): void
    {
        Http::fake();
        $talk = $this->fakeEntity('talk', [], ['topics' => [], 'tracks' => [], 'speakers' => []]);
        $source = $this->mapper->map('talk', $talk[0], new JsonApiDocument($this->fakeItem($talk)));

        $this->assertSame([], $this->ids($this->request(source: $source)));
        Http::assertNothingSent();
    }

    public function test_a_failing_backend_yields_an_empty_list_and_a_warning(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertSame([], $this->ids());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_connection_error_yields_an_empty_list(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('down')]);

        $this->assertSame([], $this->ids());
    }

    /**
     * @return iterable<string, array{0: Closure(self): RelatedRequest, 1: string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'empty criteria' => [fn (self $t): RelatedRequest => $t->request([]), 'at least one criterion'];
        yield 'limit below 1' => [fn (self $t): RelatedRequest => $t->request(limit: 0), 'at least 1'];
        yield 'unknown type' => [fn (self $t): RelatedRequest => $t->request(types: ['nope']), 'Unknown content type'];
        yield 'relation missing on the source' => [fn (self $t): RelatedRequest => $t->request(['nope']), 'no relation'];
        yield 'relation missing on a target' => [fn (self $t): RelatedRequest => $t->request(['topics'], ['talk', 'workshop']), 'no relation [topics]'];
        yield 'different kinds of entity' => [fn (self $t): RelatedRequest => new RelatedRequest($t->source(), [new Criterion('speakers', ['workshop' => 'levels'])], ['talk', 'workshop'], 10, null), 'same kind of entity'];
        yield 'order missing on a target' => [fn (self $t): RelatedRequest => new RelatedRequest($t->source(), [new Criterion('topics', ['workshop' => 'levels'])], ['talk', 'workshop'], 10, '-created'), 'is not a field of [workshop]'];
        yield 'too many requests' => [fn (self $t): RelatedRequest => $t->request(['topics', 'tracks', 'speakers'], ['talk', 'talk', 'talk', 'talk', 'talk']), 'max_requests'];
    }

    #[DataProvider('invalidRequests')]
    public function test_a_request_the_descriptor_cannot_satisfy_throws_before_anything_is_sent(Closure $make, string $message): void
    {
        Http::fake();
        $request = $make($this);

        $this->expectException(InvalidRelatedRequest::class);
        $this->expectExceptionMessage($message);

        try {
            $this->service()->find($request);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_component_renders_the_related_entries_and_lets_configuration_errors_out(): void
    {
        config(['drupal-backend' => $this->fixture('conference')]);
        $this->app->forgetInstance(Descriptor::class);
        $this->app->forgetInstance(ContentMapper::class);
        $this->fakeCandidates(['talk' => [$this->talk('other', 'c1')]]);
        $source = $this->source();

        $html = Blade::render('<x-drupal::related :entity="$entity" :criteria="[\'topics\']" order="-created"/>', ['entity' => $source]);
        $this->assertStringContainsString('<li>', $html);

        try {
            Blade::render('<x-drupal::related :entity="$entity" :criteria="[\'nope\']"/>', ['entity' => $source]);
            $this->fail('A configuration error must not be swallowed.');
        } catch (ViewException $e) {
            // Blade wraps whatever a component throws while rendering.
            $this->assertInstanceOf(InvalidRelatedRequest::class, $e->getPrevious());
        }
    }

    public function test_the_component_renders_its_slot_instead_of_the_default_view(): void
    {
        config(['drupal-backend' => $this->fixture('conference')]);
        $this->app->forgetInstance(Descriptor::class);
        $this->app->forgetInstance(ContentMapper::class);
        $this->fakeCandidates(['talk' => [$this->talk('other', 'c1')]]);

        $html = Blade::render(<<<'BLADE'
            <x-drupal::related :entity="$entity" :criteria="['topics']">
                <p>{{ $component->entities()->count() }} found</p>
                @foreach ($component->entities() as $entity)
                    <b>{{ $entity->id }}</b>
                @endforeach
            </x-drupal::related>
            BLADE, ['entity' => $this->source()]);

        $this->assertStringContainsString('1 found', $html);
        $this->assertStringContainsString('<b>other</b>', $html);
        $this->assertStringNotContainsString('<li>', $html);
    }

    public function test_the_component_renders_no_slot_when_nothing_matches(): void
    {
        config(['drupal-backend' => $this->fixture('conference')]);
        $this->app->forgetInstance(Descriptor::class);
        Http::fake(['*' => Http::response('', 503)]);

        $html = Blade::render('<x-drupal::related :entity="$entity" :criteria="[\'topics\']"><h2>More</h2></x-drupal::related>', ['entity' => $this->source()]);

        $this->assertSame('', trim($html));
    }

    public function test_the_component_renders_nothing_when_the_backend_fails(): void
    {
        config(['drupal-backend' => $this->fixture('conference')]);
        $this->app->forgetInstance(Descriptor::class);
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertSame('', trim(Blade::render('<x-drupal::related :entity="$entity" :criteria="[\'topics\']"/>', ['entity' => $this->source()])));
    }
}
