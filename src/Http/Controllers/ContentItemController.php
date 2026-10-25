<?php

namespace Proteo\DrupalBackend\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Proteo\DrupalBackend\Content\ContentFetcher;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Http\SeoFactory;
use Psr\SimpleCache\InvalidArgumentException;

class ContentItemController extends Controller
{
    /**
     * The route carries the content type key as a default. Laravel passes
     * URI parameters before defaults, so the alias comes first.
     *
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function __invoke(string $alias, string $contentType, ContentFetcher $fetcher, Descriptor $descriptor, SeoFactory $seo): View
    {
        $type = $descriptor->contentType($contentType);
        $entity = $fetcher->item($contentType, $alias);

        // The project's own view wins over the package default.
        return view()->first(array_values(array_filter([$type->views['item'] ?? null, $contentType.'.item', 'drupal::item'])), [
            $contentType => $entity,
            'entity' => $entity,
            'type' => $type,
            'seo' => $seo->for($entity, $type),
        ]);
    }
}
