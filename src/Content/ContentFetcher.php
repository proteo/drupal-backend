<?php

namespace Proteo\DrupalBackend\Content;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException as InvalidArgument;
use LogicException;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Client\PathResolver;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\RelationKind;
use Proteo\DrupalBackend\Exceptions\DrupalBadResponse;
use Proteo\DrupalBackend\Exceptions\DrupalNotFound;
use Proteo\DrupalBackend\Exceptions\DrupalUnavailable;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Fetches any content type the descriptor declares: an item by alias, a
 * page of its listing, the choices for a search filter, or a Reference.
 */
class ContentFetcher
{
    private const int OPTIONS_PER_PAGE = 50;

    private const int OPTIONS_CAP = 200;

    public function __construct(
        private readonly DrupalManager $drupal,
        private readonly Descriptor $descriptor,
        private readonly ContentMapper $content,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function item(string $contentType, string $alias): Entity
    {
        $type = $this->descriptor->contentType($contentType);
        $drupal = $this->drupalFor($type);

        $prefix = $type->aliasPrefix === '' ? '' : $type->aliasPrefix.'/';
        $path = (new PathResolver($drupal))->resolve('/'.$prefix.ltrim($alias, '/'), $type->resourceType(), $type->bundle);

        $query = ($include = $type->include('item')) === '' ? [] : ['include' => $include];
        $response = $drupal->item($path->resource(), $path->uuid(), $query);

        $entity = $this->content->map($contentType, $response->data(), $response->document);
        $this->content->decorate($response->document);

        return $entity;
    }

    /**
     * One page of the type's listing, optionally narrowed to the items that
     * relate to one entry (e.g. the talks of a speaker). A Facet's entries
     * without Primary items are not worth a row.
     *
     * @return Listing<Entity>
     *
     * @throws InvalidArgumentException
     * @throws InvalidArgument When the narrowing relation is not a content relation of the type.
     */
    public function all(string $contentType, int $page, ?int $limit = null, ?string $relation = null, ?string $uuid = null): Listing
    {
        $type = $this->descriptor->contentType($contentType);
        $limit ??= $type->perPage ?? throw new LogicException("Content type [$contentType] declares no per_page.");

        $query = ['sort' => $type->defaultSort] + $type->conditionQuery();

        if ($relation !== null) {
            $narrow = $type->relations[$relation] ?? null;

            if ($narrow === null || $narrow->kind !== RelationKind::Content || $uuid === null) {
                throw new InvalidArgument("Cannot narrow [$contentType] by [$relation].");
            }

            $query['filter['.$narrow->via.'.id]'] = $uuid;
        }

        if (($include = $type->include('listing')) !== '') {
            $query['include'] = $include;
        }

        $response = $this->drupalFor($type)->collection($type->nativeCollection, $query, $limit, $limit * (max(1, $page) - 1));

        $entities = collect($response->data())
            ->filter(fn (array $item): bool => $type->isVisible($item))
            ->map(fn (array $item): Entity => $this->content->map($contentType, $item, $response->document))
            ->values();
        $this->content->decorate($response->document);

        return new Listing($entities, $response->hasMore);
    }

    /**
     * Choices for a search filter keyed by id: entries that satisfy the type's
     * conditions, sorted by name, at most OPTIONS_CAP of them.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    public function options(string $contentType): array
    {
        $type = $this->descriptor->contentType($contentType);
        $field = $type->titleAttribute ?? throw new LogicException("Content type [$contentType] declares no title_attribute.");
        $drupal = $this->drupalFor($type);
        $options = [];

        for ($page = 0; count($options) < self::OPTIONS_CAP; $page++) {
            $response = $drupal->collection($type->nativeCollection, ['sort' => $field] + $type->conditionQuery(), self::OPTIONS_PER_PAGE, self::OPTIONS_PER_PAGE * $page);

            foreach ($response->data() as $item) {
                $id = $item['id'] ?? null;
                $name = $item['attributes'][$field] ?? null;

                if (! is_string($id) || ! is_string($name) || $name === '' || ! $type->isVisible($item)) {
                    continue;
                }

                $options[$id] = html_entity_decode($name);

                if (count($options) >= self::OPTIONS_CAP) {
                    break;
                }
            }

            if (! $response->hasMore) {
                break;
            }
        }

        return collect($options)->sortBy(fn (string $name): string => $name, SORT_NATURAL | SORT_FLAG_CASE)->all();
    }

    /**
     * A Reference is optional decoration, so every failure yields null.
     *
     * @throws InvalidArgumentException
     */
    public function reference(string $contentType, ?string $uuid): ?Entity
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $type = $this->descriptor->contentType($contentType);

        try {
            $response = $this->drupalFor($type)->item($type->resourcePath(), $uuid);
        } catch (DrupalNotFound) {
            return null;
        } catch (DrupalUnavailable|DrupalBadResponse $e) {
            Log::warning('Reference fetch failed.', ['type' => $contentType, 'uuid' => $uuid, 'exception' => $e::class]);

            return null;
        }

        // A malformed Reference is dropped and logged by the mapper.
        $entity = $this->content->mapOrNull($contentType, $response->data(), $response->document);
        $this->content->decorate($response->document);

        return $entity;
    }

    private function drupalFor(ContentType $type): CachedDrupal
    {
        return $this->drupal->source($type->source);
    }
}
