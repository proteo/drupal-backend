<?php

namespace Proteo\DrupalBackend\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Exceptions\DrupalUnavailable;
use Throwable;

/**
 * Typed access to Drupal's JSON:API. Request independent and uncached.
 */
final readonly class DrupalClient
{
    public function __construct(
        private string $baseUrl,
        private string $jsonapiPath,
        private int $timeout = 10,
        private int $connectTimeout = 3,
        private int $retries = 2,
        private int $retryDelayMs = 200,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws \Exception
     */
    public function collection(string $resource, array $query, int $limit, int $offset = 0): DrupalResponse
    {
        $url = $this->collectionUrl(new CollectionRequest($resource, $query, $limit, $offset));

        return $this->documentResponse($this->get($url), $url);
    }

    /**
     * Fetches several collections concurrently, in input order.
     *
     * Same retries and error mapping as collection(); all-or-nothing, so the
     * first failure in input order is raised.
     *
     * @param  list<CollectionRequest>  $requests
     * @return list<DrupalResponse>
     */
    public function pool(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $urls = array_map($this->collectionUrl(...), array_values($requests));
        $attempts = array_fill(0, count($urls), 0);
        $started = hrtime(true);

        $outcomes = Http::pool(function (Pool $pool) use ($urls, &$attempts): void {
            foreach ($urls as $index => $url) {
                $this->configure($pool->as((string) $index), $this->retries, $attempts[$index])->get($url);
            }
        });

        $responses = [];

        foreach ($urls as $index => $url) {
            $outcome = $outcomes[$index];
            $elapsed = $this->elapsedSince($started);

            if (! $outcome instanceof Response) {
                throw new DrupalUnavailable(
                    ['url' => $url, 'status' => null, 'duration_ms' => $elapsed, 'attempts' => max($attempts[$index], 1), 'error' => $outcome->getMessage()],
                    $outcome,
                );
            }

            $checked = $this->checked($outcome, ['url' => $url, 'status' => $outcome->status(), 'duration_ms' => $elapsed, 'attempts' => max($attempts[$index], 1)]);
            $responses[] = $this->documentResponse($this->decode($checked, $url), $url);
        }

        return $responses;
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws \Exception
     */
    public function item(string $resource, string $uuid, array $query = []): DrupalResponse
    {
        $url = $this->resourceUrl($resource.'/'.$uuid, $query);

        return $this->documentResponse($this->get($url), $url, hasMore: false);
    }

    /**
     * Resolves an alias with Drupal's Decoupled Router.
     *
     * Aliases are computed fields that JSON:API cannot filter on, so the
     * resource has to be located through this endpoint first.
     *
     * @throws \Exception
     */
    public function resolvePath(string $alias): ResolvedPath
    {
        $url = $this->baseUrl.'/router/translate-path?path='.str_replace('%2F', '/', rawurlencode($alias));
        $payload = $this->get($url);

        $individual = $payload['jsonapi']['individual'] ?? null;
        if (! is_string($individual) || $individual === '') {
            throw new DrupalNotFound(['url' => $url, 'reason' => 'no resource for path']);
        }

        // The link is absolute; only its path under the JSON:API root matters.
        $path = (string) parse_url($individual, PHP_URL_PATH);
        $prefix = $this->jsonapiPath.'/';
        $resourceName = $payload['jsonapi']['resourceName'] ?? null;
        $bundle = $payload['entity']['bundle'] ?? null;

        if (! str_starts_with($path, $prefix) || ! is_string($resourceName) || ! is_string($bundle)) {
            throw new DrupalBadResponse(['url' => $url, 'reason' => 'unexpected path translation']);
        }

        return new ResolvedPath(substr($path, strlen($prefix)), $resourceName, $bundle);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function resourceUrl(string $endpoint, array $query): string
    {
        $url = $this->baseUrl.$this->jsonapiPath.'/'.ltrim($endpoint, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function collectionUrl(CollectionRequest $request): string
    {
        $query = $request->query;
        $query['page[limit]'] = $request->limit;
        if ($request->offset > 0) {
            $query['page[offset]'] = $request->offset;
        }

        return $this->resourceUrl($request->resource, $query);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function documentResponse(array $document, string $url, bool $hasMore = true): DrupalResponse
    {
        if (! array_key_exists('data', $document) || ! is_array($document['data'])) {
            throw new DrupalBadResponse(['url' => $url, 'reason' => 'missing data']);
        }

        return new DrupalResponse($document, $hasMore && ! empty($document['links']['next']));
    }

    /**
     * Performs a GET, retrying on connection errors and 5xx, and decodes the JSON body.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    private function get(string $url): array
    {
        return $this->decode($this->send($url), $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $url): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new DrupalBadResponse(['url' => $url, 'status' => $response->status(), 'reason' => 'not JSON']);
        }

        return $decoded;
    }

    /**
     * Sends a GET and maps every non-2xx outcome to a typed exception.
     *
     * @throws \Exception
     */
    private function send(string $url): Response
    {
        $attempts = 0;
        $started = hrtime(true);

        $request = $this->configure(Http::withHeaders([]), $this->retries, $attempts);

        try {
            $response = $request->get($url);
        } catch (ConnectionException $e) {
            throw new DrupalUnavailable(
                ['url' => $url, 'status' => null, 'duration_ms' => $this->elapsedSince($started), 'attempts' => max($attempts, 1), 'error' => $e->getMessage()],
                $e,
            );
        }

        return $this->checked($response, ['url' => $url, 'status' => $response->status(), 'duration_ms' => $this->elapsedSince($started), 'attempts' => max($attempts, 1)]);
    }

    /**
     * Applies timeouts and the retry policy shared by every request.
     *
     * @param  int  $attempts  Counts the attempts made, for the failure context.
     */
    private function configure(PendingRequest $request, int $retries, int &$attempts): PendingRequest
    {
        $request->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->retry(
                $retries + 1,
                $this->retryDelayMs,
                function (Throwable $e) use (&$attempts): bool {
                    $attempts++;

                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError());
                },
                throw: false,
            );

        return $request;
    }

    /**
     * Maps every non-2xx outcome to a typed exception.
     *
     * @param  array<string, mixed>  $context
     */
    private function checked(Response $response, array $context): Response
    {
        $status = $response->status();

        if ($status === 404 || $status === 403) {
            throw new DrupalNotFound($context);
        }

        if ($status === 429 || $status >= 500) {
            throw new DrupalUnavailable($context);
        }

        if (! $response->successful()) {
            throw new DrupalBadResponse($context);
        }

        return $response;
    }

    private function elapsedSince(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
