<?php

namespace Proteo\DrupalBackend\Http;

use Illuminate\Routing\Router;
use Proteo\DrupalBackend\Descriptor\ContentType;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\Role;
use Proteo\DrupalBackend\Http\Controllers\ContentItemController;
use Proteo\DrupalBackend\Http\Controllers\ContentListingController;
use Proteo\DrupalBackend\Http\Controllers\ContentPageController;

/**
 * Registers the item and listing routes the descriptor implies:
 * `/{url_prefix}/{alias}` named `{key}`, `listing_path` named `{key}.index`, and
 * each Page's `routes` entry (slug => URL) named `page.{slug}`.
 */
class ContentRoutes
{
    public function __construct(private readonly Router $router, private readonly Descriptor $descriptor) {}

    /**
     * @param  list<string>|null  $only  Content type keys to register; null for all that have routes.
     */
    public function register(?array $only = null): void
    {
        $types = array_filter(
            $this->descriptor->contentTypes,
            fn (ContentType $type): bool => $type->served
                && ($only === null || in_array($type->key, $only, true)),
        );
        $pages = array_filter(
            $this->descriptor->contentTypes,
            fn (ContentType $type): bool => $type->role === Role::Page && $type->routes !== []
                && ($only === null || in_array($type->key, $only, true)),
        );

        if ($types === [] && $pages === []) {
            return;
        }

        $routes = $this->descriptor->routes;

        $this->router->prefix($routes->prefix)->middleware([...$routes->middleware, 'throttle:content'])->group(function () use ($types, $pages, $routes): void {
            foreach ($types as $type) {
                if ($type->hasListing()) {
                    $this->router->get($type->listingPath, ContentListingController::class)
                        ->defaults('contentType', $type->key)
                        ->name($type->key.'.index');
                }

                $this->router->get(trim($type->urlPrefix.'/{alias}', '/'), ContentItemController::class)
                    ->defaults('contentType', $type->key)
                    ->where('alias', $routes->aliasPattern)
                    ->name($type->key);
            }

            foreach ($pages as $type) {
                foreach ($type->routes as $slug => $url) {
                    $this->router->get($url, ContentPageController::class)
                        ->defaults('contentType', $type->key)
                        ->defaults('slug', (string) $slug)
                        ->name('page.'.$slug);
                }
            }
        });
    }
}
