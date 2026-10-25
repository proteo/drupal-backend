<?php

namespace Proteo\DrupalBackend\Tests\Validation;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Content\Related\NativeRelatedStrategy;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Search\NativeSearchBackend;
use Proteo\DrupalBackend\Testing\FakesDrupal;
use Proteo\DrupalBackend\Tests\Descriptor\DescriptorTestCase;
use Proteo\DrupalBackend\Validation\BackendCheck;
use Proteo\DrupalBackend\Validation\BackendValidator;
use Proteo\DrupalBackend\Validation\CheckContext;
use Proteo\DrupalBackend\Validation\CheckResult;
use Proteo\DrupalBackend\Validation\CheckStatus;

class BackendValidatorTest extends DescriptorTestCase
{
    use FakesDrupal;

    private Descriptor $descriptor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['drupal.cache.enabled' => false, 'drupal.timeout' => 5, 'drupal.connect_timeout' => 2, 'drupal.retries' => 0, 'drupal.retry_delay_ms' => 0]);

        $this->useFixture('conference', ['sources' => ['reviews' => ['webhook' => ['secret' => 'reviews-secret']]]]);
    }

    protected function drupalDescriptor(): Descriptor
    {
        return $this->descriptor;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function useFixture(string $name, array $patch = []): void
    {
        $this->descriptor = $this->descriptor(array_replace_recursive($this->fixture($name), $patch));
        $this->app->instance(Descriptor::class, $this->descriptor);
    }

    /**
     * A backend that satisfies the descriptor. Each override is a URL fragment
     * (decoded) mapped to the response, or a callback returning one, for the
     * requests that should misbehave.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function backend(array $overrides = []): void
    {
        // Stubs accumulate and the first match wins, so each call starts from a fresh factory.
        Http::swap(new Factory);

        Http::fake(function (Request $request) use ($overrides) {
            $url = urldecode($request->url());

            foreach ($overrides as $needle => $response) {
                // A leading `=` matches the URL without its query exactly.
                $matches = str_starts_with($needle, '=')
                    ? strtok($url, '?') === substr($needle, 1)
                    : str_contains($url, $needle);

                if ($matches) {
                    return $response instanceof Closure ? $response($url) : $response;
                }
            }

            return Http::response($this->healthy($url));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function healthy(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ($this->descriptor->sources as $source) {
            if ($path === $source->jsonapiPath) {
                $types = array_filter($this->descriptor->contentTypes, fn (ContentType $t): bool => $t->source === $source->name);

                return ['links' => ['self' => ['href' => $url]] + array_fill_keys(array_map(fn (ContentType $t): string => $t->resourceType(), $types), ['href' => $url])];
            }
        }

        if (str_contains($path, 'translate-path')) {
            $source = array_find($this->descriptor->sources, fn ($s): bool => str_starts_with($url, $s->baseUrl));
            $type = array_find($this->descriptor->contentTypes, fn (ContentType $t): bool => $t->source === $source->name);

            return $this->fakeAlias($type->key, 'e34b1bd7-3e4a-4fd4-95e3-1eb97741f0ce');
        }

        foreach ($this->descriptor->contentTypes as $type) {
            if (str_ends_with($path, $this->descriptor->source($type->source)->jsonapiPath.'/'.$type->nativeCollection)) {
                return $this->fakeCollection($type->key, 1);
            }
        }

        return ['data' => []];
    }

    /**
     * @return list<CheckResult>
     */
    private function results(?string $only = null): array
    {
        return app(BackendValidator::class)->validate($only);
    }

    private function find(string $scope, string $check, ?string $only = null): CheckResult
    {
        foreach ($this->results($only) as $result) {
            if ($result->scope === $scope && str_starts_with($result->check, $check)) {
                return $result;
            }
        }

        $this->fail("No result for $scope / $check.");
    }

    private function assertStatus(CheckStatus $status, string $scope, string $check): CheckResult
    {
        $result = $this->find($scope, $check);

        $this->assertSame($status, $result->status, "$scope / $check: $result->found");

        return $result;
    }

    public function test_a_healthy_backend_has_no_failures_or_warnings(): void
    {
        $this->backend();

        foreach ($this->results() as $result) {
            $this->assertSame(CheckStatus::Pass, $result->status, "$result->scope / $result->check: $result->found");
        }
    }

    public function test_the_configured_native_limit_is_reported_not_probed(): void
    {
        $this->backend();

        $result = $this->assertStatus(CheckStatus::Pass, 'source:default', 'Native limit');

        $this->assertSame((string) $this->drupalDescriptor()->search->nativeLimit, $result->found);
    }

    public function test_an_unknown_descriptor_key_warns_without_failing(): void
    {
        config(['drupal-backend' => ['search' => ['per_pge' => 5]]]);

        $warnings = array_values(array_filter(
            $this->app->make(BackendValidator::class)->validate(),
            fn (CheckResult $r): bool => $r->scope === 'descriptor',
        ));

        $this->assertCount(1, $warnings);
        $this->assertSame(CheckStatus::Warn, $warnings[0]->status);
        $this->assertStringContainsString('search.per_pge', $warnings[0]->found);
    }

    public function test_an_unknown_source_argument_fails_with_a_message(): void
    {
        $this->artisan('drupal:validate', ['source' => 'nope'])
            ->expectsOutputToContain('Unknown source [nope]')
            ->assertFailed();
    }

    public function test_root_passes_and_fails_when_unreachable(): void
    {
        $this->backend();
        $this->assertStatus(CheckStatus::Pass, 'source:default', 'JSON:API root');

        $this->backend(['=https://drupal.test/jsonapi' => fn () => throw new ConnectionException('down')]);
        $this->assertStatus(CheckStatus::Fail, 'source:default', 'JSON:API root');
    }

    public function test_nothing_else_is_tried_for_a_source_whose_root_fails(): void
    {
        $this->backend(['=https://drupal.test/jsonapi' => Http::response('', 404)]);

        $scopes = array_unique(array_map(fn (CheckResult $r): string => $r->scope, $this->results('default')));

        $this->assertSame(['source:default'], array_values($scopes));
    }

    public function test_resource_types_pass_and_fail_when_not_listed(): void
    {
        $this->backend();
        $this->assertStatus(CheckStatus::Pass, 'talk', 'Resource type');

        $this->backend(['=https://drupal.test/jsonapi' => Http::response(['links' => ['self' => ['href' => 'x']]])]);
        $result = $this->assertStatus(CheckStatus::Fail, 'talk', 'Resource type');
        $this->assertStringContainsString('contract section', (string) $result->hint);
    }

    public function test_item_fetch_passes_warns_on_an_empty_collection_and_fails_on_an_error(): void
    {
        $this->backend();
        $this->assertStatus(CheckStatus::Pass, 'talk', 'Fields');

        $this->backend(['drupal.test/jsonapi/talk' => Http::response(['data' => []])]);
        $this->assertStatus(CheckStatus::Warn, 'talk', 'Item fetch');

        $this->backend(['drupal.test/jsonapi/talk' => Http::response('', 400)]);
        $this->assertStatus(CheckStatus::Fail, 'talk', 'Item fetch');
    }

    public function test_fields_fail_when_a_declared_field_is_missing(): void
    {
        $document = $this->fakeCollection('talk', 1);
        unset($document['data'][0]['attributes']['short_description']);
        $this->backend(['drupal.test/jsonapi/talk' => Http::response($document)]);

        $result = $this->assertStatus(CheckStatus::Fail, 'talk', 'Fields');

        $this->assertSame('short_description', $result->expected);
    }

    public function test_relationships_fail_when_required_and_warn_when_optional(): void
    {
        $document = $this->fakeCollection('talk', 1);
        unset($document['data'][0]['relationships']['topic']);
        $this->backend(['drupal.test/jsonapi/talk' => Http::response($document)]);
        $this->assertStatus(CheckStatus::Fail, 'talk', 'Relationships');

        $this->useFixture('article');
        $document = $this->fakeCollection('article', 1);
        unset($document['data'][0]['relationships']['field_topic']);
        $this->backend(['cms.test/jsonapi/article' => Http::response($document)]);
        $this->assertStatus(CheckStatus::Warn, 'article', 'Relationships');
    }

    public function test_decoupled_router_passes_warns_without_a_sample_and_fails_on_a_mismatch(): void
    {
        $this->backend();
        $this->assertStatus(CheckStatus::Pass, 'source:default', 'Decoupled Router');

        $this->useFixture('article');
        $this->backend(['cms.test/jsonapi/' => function (string $url) {
            $document = $this->healthy($url);

            foreach ($document['data'] ?? [] as $index => $item) {
                unset($document['data'][$index]['attributes']['path']);
            }

            return Http::response($document);
        }]);
        $this->assertStatus(CheckStatus::Warn, 'source:default', 'Decoupled Router');

        $this->useFixture('conference');
        $this->backend(['translate-path' => Http::response($this->fakeAlias('speaker', 'e34b1bd7-3e4a-4fd4-95e3-1eb97741f0ce'))]);
        $this->assertStatus(CheckStatus::Fail, 'source:default', 'Decoupled Router');

        $this->backend(['translate-path' => Http::response('', 404)]);
        $this->assertStatus(CheckStatus::Fail, 'source:default', 'Decoupled Router');
    }

    public function test_search_is_not_checked_when_disabled(): void
    {
        $this->useFixture('article');
        $this->backend();

        $scopes = array_map(fn (CheckResult $r): string => $r->scope, $this->results());

        $this->assertNotContains('search', $scopes);
        $this->assertSame([], array_filter($this->results(), fn (CheckResult $r): bool => $r->check === 'Native limit'));
    }

    public function test_webhook_secret_passes_and_fails_for_the_default_source_but_only_warns_for_others(): void
    {
        $this->backend();
        $this->assertStatus(CheckStatus::Pass, 'source:default', 'Webhook secret');
        $this->assertStatus(CheckStatus::Pass, 'source:reviews', 'Webhook secret');

        $this->useFixture('conference');
        $this->backend();
        $this->assertStatus(CheckStatus::Warn, 'source:reviews', 'Webhook secret');

        $this->useFixture('conference', ['sources' => ['default' => ['webhook' => ['secret' => '']]]]);
        $this->backend();
        $this->assertStatus(CheckStatus::Fail, 'source:default', 'Webhook secret');
    }

    public function test_the_command_exits_non_zero_on_failure_and_zero_otherwise(): void
    {
        $this->backend();
        $this->artisan('drupal:validate')->assertSuccessful();

        $this->backend(['translate-path' => Http::response('', 404)]);
        $this->artisan('drupal:validate')->assertFailed();
    }

    public function test_warnings_do_not_fail_the_command_and_json_lists_the_results(): void
    {
        // The conference fixture sets no webhook secret on its second Source, which only warns.
        $this->useFixture('conference');
        $this->backend();

        $exit = Artisan::call('drupal:validate', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['ok']);
        $this->assertContains('warn', array_column($report['results'], 'status'));
    }

    public function test_the_source_argument_limits_the_checks(): void
    {
        $this->backend();

        $scopes = array_unique(array_map(fn (CheckResult $r): string => $r->scope, $this->results('reviews')));

        $this->assertContains('source:reviews', $scopes);
        $this->assertNotContains('source:default', $scopes);
    }

    public function test_native_search_probes_pass_and_fail_on_a_rejected_field(): void
    {
        $this->backend();
        $this->assertStatus(CheckStatus::Pass, 'talk', 'Native `CONTAINS` on `speaker.title`');
        $this->assertStatus(CheckStatus::Pass, 'talk', 'Native filter `topic.id`');
        $this->assertStatus(CheckStatus::Pass, 'talk', 'Native sort `-created`');

        $this->backend(['speaker.title' => Http::response('', 400)]);
        $result = $this->assertStatus(CheckStatus::Fail, 'talk', 'Native `CONTAINS` on `speaker.title`');
        $this->assertStringContainsString('contract section "Content type `talk`"', (string) $result->hint);
    }

    public function test_a_tagged_check_runs_once_per_source_and_a_throwing_one_fails(): void
    {
        $this->backend();
        $seen = new \ArrayObject;
        $this->app->bind('test.check', fn () => new class($seen) implements BackendCheck
        {
            public function __construct(private readonly \ArrayObject $seen) {}

            public function run(CheckContext $context): array
            {
                $this->seen[] = $context->source->name;

                return [new CheckResult(CheckStatus::Pass, 'ext', 'Custom', 'x', 'y')];
            }
        });
        $this->app->bind('test.broken', fn () => new class implements BackendCheck
        {
            public function run(CheckContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        });
        $this->app->tag(['test.check', 'test.broken'], 'drupal-backend.checks');

        $results = $this->results();

        $this->assertSame(array_keys($this->descriptor->sources), $seen->getArrayCopy());
        $this->assertNotEmpty(array_filter($results, fn (CheckResult $r): bool => $r->scope === 'ext' && $r->check === 'Custom'));
        $broken = array_values(array_filter($results, fn (CheckResult $r): bool => $r->check === 'Extension check'))[0];
        $this->assertSame(CheckStatus::Fail, $broken->status);
        $this->assertSame(\RuntimeException::class, $broken->found);
    }

    public function test_the_extensions_scope_names_what_is_bound(): void
    {
        $this->backend();

        $this->assertSame(NativeSearchBackend::class, $this->assertStatus(CheckStatus::Pass, 'extensions', 'Search backend')->found);
        $this->assertSame(NativeRelatedStrategy::class, $this->assertStatus(CheckStatus::Pass, 'extensions', 'Related strategy')->found);
        $this->assertSame('exact counts no, index-only booleans no, fulltext no', $this->find('extensions', 'Capabilities')->found);
        $this->assertSame('0', $this->find('extensions', 'Tagged checks')->found);
        $this->assertNotNull(collect($this->results())->first(fn (CheckResult $r): bool => $r->scope === 'extensions'));
    }

    public function test_a_boolean_without_a_native_path_fails_when_the_backend_cannot_answer_it(): void
    {
        $this->useFixture('conference', ['content_types' => ['talk' => ['filters' => ['materials' => ['fields' => ['recording' => ['native' => null]]]]]]]);
        $this->backend();

        $result = $this->find('extensions', 'Descriptor');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('filters.materials.fields.recording.native', $result->found);
    }

    public function test_the_extensions_scope_is_left_out_when_one_source_is_checked(): void
    {
        $this->backend();

        $this->assertSame([], array_filter($this->results('default'), fn (CheckResult $r): bool => $r->scope === 'extensions'));
    }
}
