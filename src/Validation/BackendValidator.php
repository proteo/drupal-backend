<?php

namespace Proteo\DrupalBackend\Validation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Content\Related\RelatedStrategy;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\DescriptorSchema;
use Proteo\DrupalBackend\Descriptor\DescriptorValidator;
use Proteo\DrupalBackend\Descriptor\Filter;
use Proteo\DrupalBackend\Descriptor\FilterType;
use Proteo\DrupalBackend\Descriptor\Source;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Exceptions\DrupalException;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Search\SearchBackend;
use Throwable;

/**
 * Checks a live backend against the descriptor, per Source and cheapest check
 * first. When a Source's JSON:API root is unreachable nothing else is tried
 * for it, since every later check would fail for the same reason.
 */
class BackendValidator
{
    /**
     * @param  iterable<BackendCheck>  $checks  Services tagged `drupal-backend.checks`.
     */
    public function __construct(
        private readonly Descriptor $descriptor,
        private readonly DrupalManager $drupal,
        private readonly iterable $checks = [],
        private readonly ?Container $container = null,
    ) {}

    /**
     * @param  ?string  $only  Check this Source alone.
     * @return list<CheckResult>
     */
    public function validate(?string $only = null): array
    {
        $results = $this->unknownKeys();

        foreach ($this->descriptor->sources as $source) {
            if ($only !== null && $source->name !== $only) {
                continue;
            }

            $client = $this->drupal->client($source);
            $types = array_filter($this->descriptor->contentTypes, fn (ContentType $type): bool => $type->source === $source->name);
            $samples = [];

            $root = $this->root($source, $types);
            array_push($results, ...$root);

            if ($this->failed($root)) {
                continue;
            }

            array_push($results, ...$this->contentTypes($client, $types, $samples));
            array_push($results, ...$this->alias($client, $source, $samples));

            if ($this->descriptor->search->enabled) {
                // Search runs on the default Source, so only it uses the limit.
                if ($source->name === $this->descriptor->defaultSource) {
                    $results[] = new CheckResult(CheckStatus::Pass, "source:$source->name", 'Native limit', 'a configured value, not probed', (string) $this->descriptor->search->nativeLimit, 'Raise `search.native_limit` only if the backend page size was raised.');
                }

                array_push($results, ...$this->nativeSearch($client, $types));
            }

            array_push($results, ...$this->extensionChecks(new CheckContext($this->descriptor, $source, $client, $only)));

            $results[] = $this->webhook($source);
        }

        if ($only === null && $this->container !== null) {
            array_push($results, ...$this->extensions());
        }

        return $results;
    }

    /**
     * The checks other packages tag. A throwing check is a failure, so one broken extension cannot hide the rest.
     *
     * @return list<CheckResult>
     */
    private function extensionChecks(CheckContext $context): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                array_push($results, ...$check->run($context));
            } catch (Throwable $e) {
                $results[] = new CheckResult(CheckStatus::Fail, "source:{$context->source->name}", 'Extension check', $check::class.' runs without error', $e::class, 'See the log of the extension that provides the check.');
            }
        }

        return $results;
    }

    /**
     * What is bound at the three seams, so a CI log shows which extension is active.
     *
     * @return list<CheckResult>
     */
    private function extensions(): array
    {
        $capabilities = $this->container->make(SearchBackend::class)->capabilities();
        $flag = fn (bool $value): string => $value ? 'yes' : 'no';

        // The boot check cannot ask the backend, so the rules that depend on it run here.
        $problems = array_map(
            fn (string $problem): CheckResult => new CheckResult(CheckStatus::Fail, 'extensions', 'Descriptor', 'fits the bound search backend', $problem, 'Declare the native path, or install a search backend that answers it.'),
            (new DescriptorValidator)->problems($this->descriptor, $capabilities),
        );

        return [
            ...$problems,
            new CheckResult(CheckStatus::Pass, 'extensions', 'Search backend', 'a bound SearchBackend', $this->container->make(SearchBackend::class)::class),
            new CheckResult(CheckStatus::Pass, 'extensions', 'Related strategy', 'a bound RelatedStrategy', $this->container->make(RelatedStrategy::class)::class),
            new CheckResult(CheckStatus::Pass, 'extensions', 'Capabilities', 'what the backend declares', sprintf('exact counts %s, index-only booleans %s, fulltext %s', $flag($capabilities->exactCounts), $flag($capabilities->indexOnlyBooleans), $flag($capabilities->fulltext))),
            new CheckResult(CheckStatus::Pass, 'extensions', 'Tagged checks', 'drupal-backend.checks', (string) iterator_count($this->checks instanceof \Traversable ? $this->checks : new \ArrayIterator($this->checks))),
            new CheckResult(CheckStatus::Pass, 'extensions', 'Contract contributors', 'drupal-backend.contract', (string) iterator_count($this->container->tagged('drupal-backend.contract'))),
        ];
    }

    /**
     * The native collection must accept what a search sends it: partial-word matches, each
     * filter path, each sort and each filterable condition. A limit-1 request that the
     * backend rejects with a 400 means the field is not exposed or filterable.
     *
     * @param  array<string, ContentType>  $types
     * @return list<CheckResult>
     */
    private function nativeSearch(DrupalClient $client, array $types): array
    {
        $results = [];

        foreach (array_filter($types, fn (ContentType $type): bool => $type->searchable) as $type) {
            $contract = "contract section \"Content type `$type->key`\"";

            foreach (array_keys($type->termFields + $type->relatedTermFields) as $path) {
                $results[] = $this->nativeProbe($client, $type, "Native `CONTAINS` on `$path`", [
                    'filter[probe][condition][path]' => $path,
                    'filter[probe][condition][operator]' => 'CONTAINS',
                    'filter[probe][condition][value]' => 'a',
                ], "Expose `$path` for filtering; $contract.");
            }

            foreach ($type->filters as $filter) {
                foreach ($this->nativeFilterQueries($filter) as $label => $query) {
                    $results[] = $this->nativeProbe($client, $type, "Native filter `$label`", $query, "Make `$label` filterable on the native collection; $contract.");
                }
            }

            foreach (array_unique(array_values($type->sorts)) as $sort) {
                $results[] = $this->nativeProbe($client, $type, "Native sort `$sort`", ['sort' => $sort], "Make the sort field available on the native collection; $contract.");
            }

            foreach ($type->filterableConditions() as $condition) {
                $results[] = $this->nativeProbe($client, $type, "Native condition on `$condition->attribute`", $condition->query($condition->attribute), "Make `$condition->attribute` filterable with `$condition->operator`; $contract.");
            }
        }

        return $results;
    }

    /**
     * @return array<string, array<string, mixed>> Native path to its probe query.
     */
    private function nativeFilterQueries(Filter $filter): array
    {
        $queries = [];

        if ($filter->type === FilterType::Boolean) {
            foreach ($filter->fields as $field) {
                if ($field->native !== null) {
                    $queries[$field->native] = $field->present
                        ? ['filter[probe][condition][path]' => $field->native, 'filter[probe][condition][operator]' => 'IS NOT NULL']
                        : ["filter[$field->native]" => 1];
                }
            }

            return $queries;
        }

        if ($filter->nativePath !== null) {
            $queries[$filter->nativePath] = [
                'filter[probe][condition][path]' => $filter->nativePath,
                'filter[probe][condition][operator]' => 'IS NOT NULL',
            ];
        }

        return $queries;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function nativeProbe(DrupalClient $client, ContentType $type, string $check, array $query, string $hint): CheckResult
    {
        try {
            $client->collection($type->nativeCollection, $query, 1);
        } catch (DrupalBadResponse) {
            return new CheckResult(CheckStatus::Fail, $type->key, $check, 'accepted by the native collection', 'rejected', $hint);
        } catch (DrupalException $e) {
            return new CheckResult(CheckStatus::Fail, $type->key, $check, 'accepted by the native collection', $this->describe($e), $hint);
        }

        return new CheckResult(CheckStatus::Pass, $type->key, $check, 'accepted by the native collection', 'ok');
    }

    /**
     * A typo is ignored by the factory, so say so; never a failure.
     *
     * @return list<CheckResult>
     */
    private function unknownKeys(): array
    {
        return array_map(
            fn (string $problem): CheckResult => new CheckResult(
                CheckStatus::Warn,
                'descriptor',
                'known keys',
                'only keys the package reads',
                $problem,
                'Fix the spelling or remove the key; the package ignores it.',
            ),
            DescriptorSchema::unknownKeys((array) config('drupal-backend')),
        );
    }

    /**
     * @param  list<CheckResult>  $results
     */
    private function failed(array $results): bool
    {
        return array_any($results, fn (CheckResult $result): bool => $result->status === CheckStatus::Fail);
    }

    /**
     * @param  array<string, ContentType>  $types
     * @return list<CheckResult>
     */
    private function root(Source $source, array $types): array
    {
        $scope = "source:$source->name";
        $url = $source->baseUrl.$source->jsonapiPath;

        try {
            $response = Http::timeout(10)->connectTimeout(3)->get($url);
        } catch (ConnectionException $e) {
            return [new CheckResult(CheckStatus::Fail, $scope, 'JSON:API root', "$url answers", 'connection failed', 'Check `base_url` and `jsonapi_path` of the Source; contract section "Sources".')];
        }

        $links = $response->json('links');

        if (! $response->successful() || ! is_array($links)) {
            return [new CheckResult(CheckStatus::Fail, $scope, 'JSON:API root', "$url lists resource types", 'HTTP '.$response->status(), 'Enable the JSON:API module and check `jsonapi_path`; contract section "Required modules".')];
        }

        $results = [new CheckResult(CheckStatus::Pass, $scope, 'JSON:API root', "$url answers", 'ok')];

        foreach ($types as $type) {
            $results[] = array_key_exists($type->resourceType(), $links)
                ? new CheckResult(CheckStatus::Pass, $type->key, 'Resource type', $type->resourceType(), 'listed')
                : new CheckResult(CheckStatus::Fail, $type->key, 'Resource type', $type->resourceType(), 'not listed', "Create the bundle or expose it through JSON:API; contract section \"Content type `$type->key`\".");
        }

        return $results;
    }

    /**
     * One item per content type with its declared includes; every declared
     * field and relationship must be present in it.
     *
     * @param  array<string, ContentType>  $types
     * @param  array<string, string>  $samples  Filled with content type key to a sample alias.
     * @return list<CheckResult>
     */
    private function contentTypes(DrupalClient $client, array $types, array &$samples): array
    {
        $results = [];

        foreach ($types as $type) {
            $include = implode(',', array_filter([$type->include('item'), $type->include('listing')]));
            $query = $include === '' ? [] : ['include' => $include];

            try {
                $response = $client->collection($type->nativeCollection, $query, 1);
            } catch (DrupalException $e) {
                $results[] = new CheckResult(CheckStatus::Fail, $type->key, 'Item fetch', "`$type->nativeCollection` answers with includes `$include`", $this->describe($e), "Check the include paths and relationship names; contract section \"Content type `$type->key`\".");

                continue;
            }

            $item = $response->data()[0] ?? null;

            if (! is_array($item)) {
                $results[] = new CheckResult(CheckStatus::Warn, $type->key, 'Item fetch', 'at least one item to inspect', 'empty collection', 'Create a sample entity so fields and relationships can be checked.');

                continue;
            }

            if (is_string($item['attributes']['path'] ?? null) && $item['attributes']['path'] !== '') {
                $samples[$type->key] = $item['attributes']['path'];
            }

            array_push($results, ...$this->shape($type, $item));
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<CheckResult>
     */
    private function shape(ContentType $type, array $item): array
    {
        $attributes = $item['attributes'] ?? [];
        $relationships = $item['relationships'] ?? [];
        $results = [];

        $missingFields = [];
        foreach ($type->fields as $field) {
            // A dotted source such as `description.value` lives under its first segment.
            if (! array_key_exists(explode('.', $field->source)[0], $attributes)) {
                $missingFields[] = $field->source;
            }
        }

        $results[] = $missingFields === []
            ? new CheckResult(CheckStatus::Pass, $type->key, 'Fields', 'every declared field present', 'ok')
            : new CheckResult(CheckStatus::Fail, $type->key, 'Fields', implode(', ', $missingFields), 'missing from the item', "Add the fields or fix `source`; contract section \"Content type `$type->key`\".");

        $missingRelations = [];
        $missingOptional = [];
        foreach ($type->relations as $relation) {
            if (! array_key_exists($relation->via, $relationships)) {
                $relation->optional ? $missingOptional[] = $relation->via : $missingRelations[] = $relation->via;
            }
        }

        $results[] = match (true) {
            $missingRelations !== [] => new CheckResult(CheckStatus::Fail, $type->key, 'Relationships', implode(', ', $missingRelations), 'missing from the item', "Add the reference fields or fix `via`; contract section \"Content type `$type->key`\"."),
            $missingOptional !== [] => new CheckResult(CheckStatus::Warn, $type->key, 'Relationships', implode(', ', $missingOptional), 'optional, missing from the item', 'Optional relations are dropped when absent.'),
            default => new CheckResult(CheckStatus::Pass, $type->key, 'Relationships', 'every declared relationship present', 'ok'),
        };

        return $results;
    }

    /**
     * Decoupled Router must turn a real alias into the right resource type and bundle.
     *
     * @param  array<string, string>  $samples
     * @return list<CheckResult>
     */
    private function alias(DrupalClient $client, Source $source, array $samples): array
    {
        $scope = "source:$source->name";

        if ($samples === []) {
            return [new CheckResult(CheckStatus::Warn, $scope, 'Decoupled Router', 'a sample alias resolves', 'no sample alias available', 'Create content with a path alias so the router can be checked.')];
        }

        $key = array_key_first($samples);
        $type = $this->descriptor->contentType($key);

        try {
            $resolved = $client->resolvePath($samples[$key]);
        } catch (DrupalException $e) {
            return [new CheckResult(CheckStatus::Fail, $scope, 'Decoupled Router', "`{$samples[$key]}` resolves", $this->describe($e), 'Enable the Decoupled Router module; contract section "Required modules".')];
        }

        return [$resolved->resourceName === $type->resourceType() && $resolved->bundle === $type->bundle
            ? new CheckResult(CheckStatus::Pass, $scope, 'Decoupled Router', $type->resourceType(), 'resolved')
            : new CheckResult(CheckStatus::Fail, $scope, 'Decoupled Router', $type->resourceType(), "$resolved->resourceName ($resolved->bundle)", 'The alias resolved to another resource type; check the content type declaration.')];
    }

    /**
     * Whether a secret is set says nothing about whether Drupal sends the request.
     */
    private function webhook(Source $source): CheckResult
    {
        $scope = "source:$source->name";
        $expected = 'a webhook secret';

        if ($source->webhookSecret !== null && $source->webhookSecret !== '') {
            return new CheckResult(CheckStatus::Pass, $scope, 'Webhook secret', $expected, 'configured (cannot verify that Drupal sends the request)');
        }

        // Only the default Source must invalidate; a secondary one is inert without a secret.
        return new CheckResult(
            $source->name === $this->descriptor->defaultSource ? CheckStatus::Fail : CheckStatus::Warn,
            $scope,
            'Webhook secret',
            $expected,
            'not set',
            'Set `webhook.secret` on the Source and `cache_webhook.secret` in Drupal; contract section "Drupal changes".',
        );
    }

    private function describe(Throwable $e): string
    {
        return match (true) {
            $e instanceof DrupalNotFound => 'not found or forbidden',
            $e instanceof DrupalBadResponse => 'rejected by Drupal',
            default => 'unavailable',
        };
    }
}
