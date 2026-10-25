<?php

namespace Proteo\DrupalBackend\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Proteo\DrupalBackend\Content\ContentFetcher;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Http\SeoFactory;
use Psr\SimpleCache\InvalidArgumentException;

class ContentPageController extends Controller
{
    /**
     * A Page is fetched by its slug and rendered by `pages.{slug}`. The route
     * carries the content type key and the slug as defaults.
     *
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function __invoke(string $contentType, string $slug, ContentFetcher $fetcher, Descriptor $descriptor, SeoFactory $seo): View
    {
        $type = $descriptor->contentType($contentType);
        $entity = $fetcher->item($contentType, $slug);

        return view()->first(['pages.'.$slug, 'drupal::page'], [
            $contentType => $entity,
            'entity' => $entity,
            'type' => $type,
            'seo' => $seo->for($entity, $type),
        ]);
    }
}
