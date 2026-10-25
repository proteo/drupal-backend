<?php

namespace Proteo\DrupalBackend\Http;

use Illuminate\Routing\Router;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Http\Controllers\DrupalWebhookController;
use Proteo\DrupalBackend\Http\Controllers\SearchPageController;
use Proteo\DrupalBackend\Http\Controllers\SearchSuggestionsController;
use Proteo\DrupalBackend\Http\Middleware\VerifyDrupalWebhook;

/**
 * Routes every project with a descriptor gets: the webhook for each Source
 * and, when search is enabled, the search page and the suggestions endpoint.
 */
class InfrastructureRoutes
{
    public function __construct(private readonly Router $router, private readonly Descriptor $descriptor) {}

    public function register(): void
    {
        $this->webhook();

        if ($this->descriptor->search->enabled) {
            $this->searchPage();
            $this->suggestions();
        }
    }

    /**
     * No web group: the webhook is authenticated by its signature, so it has
     * no session and no CSRF token. A Source without a secret answers 404.
     */
    private function webhook(): void
    {
        $path = $this->descriptor->webhook->path;
        $middleware = ['throttle:drupal-webhook', VerifyDrupalWebhook::class];

        $this->router->post($path, DrupalWebhookController::class)
            ->defaults('source', $this->descriptor->defaultSource)
            ->middleware($middleware)
            ->name('hooks.drupal');

        $this->router->post($path.'/{source}', DrupalWebhookController::class)
            ->where('source', '[A-Za-z0-9_-]+')
            ->middleware($middleware)
            ->name('hooks.drupal.source');
    }

    private function searchPage(): void
    {
        $routes = $this->descriptor->routes;

        $this->router->prefix($routes->prefix)
            ->middleware([...$routes->middleware, 'throttle:content'])
            ->group(function (): void {
                $this->router->get('search', SearchPageController::class)->name('search');
            });
    }

    private function suggestions(): void
    {
        $routes = $this->descriptor->routes;

        $this->router->prefix($routes->prefix)
            ->middleware([...$routes->middleware, 'throttle:search-suggest'])
            ->group(function (): void {
                $this->router->get('search/suggest', SearchSuggestionsController::class)->name('search.suggest');
            });
    }
}
