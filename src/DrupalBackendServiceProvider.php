<?php

namespace Proteo\DrupalBackend;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Proteo\DrupalBackend\Cache\CachedDrupal;
use Proteo\DrupalBackend\Cache\DrupalManager;
use Proteo\DrupalBackend\Client\DrupalClient;
use Proteo\DrupalBackend\Console\DrupalContract;
use Proteo\DrupalBackend\Console\FlushDrupalCache;
use Proteo\DrupalBackend\Console\ValidateDrupalBackend;
use Proteo\DrupalBackend\Content\ContentMapper;
use Proteo\DrupalBackend\Content\EntityFactory;
use Proteo\DrupalBackend\Content\Related\NativeRelatedStrategy;
use Proteo\DrupalBackend\Content\Related\RelatedStrategy;
use Proteo\DrupalBackend\Contract\ContractGenerator;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\DescriptorFactory;
use Proteo\DrupalBackend\Descriptor\DescriptorLoader;
use Proteo\DrupalBackend\Descriptor\DescriptorValidator;
use Proteo\DrupalBackend\Http\ContentRoutes;
use Proteo\DrupalBackend\Http\InfrastructureRoutes;
use Proteo\DrupalBackend\Search\NativeQueries;
use Proteo\DrupalBackend\Search\NativeSearchBackend;
use Proteo\DrupalBackend\Search\SearchBackend;
use Proteo\DrupalBackend\Search\TermSanitiser;
use Proteo\DrupalBackend\Validation\BackendValidator;
use Psr\Log\LoggerInterface;

class DrupalBackendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/drupal-backend.php', 'drupal-backend');
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/drupal.php', 'drupal');

        $this->app->singleton(DescriptorLoader::class, fn ($app) => new DescriptorLoader(
            new DescriptorFactory,
            new DescriptorValidator,
            $app->make(LoggerInterface::class),
            $app->isProduction(),
        ));

        $this->app->singleton(Descriptor::class, fn ($app) => $app->make(DescriptorLoader::class)->load($app['config']->get('drupal-backend')));

        $this->app->singleton(DrupalManager::class);

        // Code that wants no particular Source gets the default one.
        $this->app->singleton(CachedDrupal::class, fn ($app) => $app->make(DrupalManager::class)->source());
        $this->app->singleton(DrupalClient::class, fn ($app) => $app->make(DrupalManager::class)->client(
            $app->make(Descriptor::class)->source($app->make(Descriptor::class)->defaultSource),
        ));

        $this->app->bind(RelatedStrategy::class, NativeRelatedStrategy::class);

        $this->app->bind(BackendValidator::class, fn ($app) => new BackendValidator(
            $app->make(Descriptor::class),
            $app->make(DrupalManager::class),
            $app->tagged('drupal-backend.checks'),
            $app,
        ));
        $this->app->bind(ContractGenerator::class, fn ($app) => new ContractGenerator($app->make(Descriptor::class), $app->tagged('drupal-backend.contract')));

        $this->app->singleton(NativeQueries::class, fn ($app) => new NativeQueries($app->make(Descriptor::class)->search));
        $this->app->bind(SearchBackend::class, NativeSearchBackend::class);

        $this->app->singleton(TermSanitiser::class, fn ($app) => new TermSanitiser($app->make(Descriptor::class)->search));

        $this->app->singleton(EntityFactory::class);
        $this->app->singleton(ContentMapper::class, fn ($app) => new ContentMapper(
            $app->make(Descriptor::class),
            $app->make(EntityFactory::class),
            rtrim((string) $app['config']->get('app.url'), '/'),
        ));
    }

    public function boot(): void
    {
        // Projects override a view by placing it in resources/views/vendor/drupal.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'drupal');

        Livewire::addNamespace(
            'drupal',
            classNamespace: 'Proteo\\DrupalBackend\\Livewire',
            classPath: __DIR__.'/Livewire',
            classViewPath: __DIR__.'/../resources/views/livewire',
        );

        Blade::componentNamespace('Proteo\\DrupalBackend\\View\\Components', 'drupal');

        $this->publishes([__DIR__.'/../config/drupal-backend.php' => config_path('drupal-backend.php')], 'drupal-backend-config');
        $this->publishes([__DIR__.'/../config/drupal.php' => config_path('drupal.php')], 'drupal-backend-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushDrupalCache::class, DrupalContract::class, ValidateDrupalBackend::class]);
        }

        // Fail at boot, not on the first request, when the descriptor is broken.
        if ($this->app['config']->get('drupal-backend.content_types') !== []) {
            $this->app->make(Descriptor::class);
        }

        // Projects that turn `routes.auto` off call this where they want the routes.
        Route::macro('drupalContent', function (string ...$contentTypes): void {
            app(ContentRoutes::class)->register($contentTypes === [] ? null : array_values($contentTypes));
        });

        $this->defineRateLimiters();
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached() || $this->app['config']->get('drupal-backend.content_types') === []) {
            return;
        }

        $descriptor = $this->app->make(Descriptor::class);

        $this->app->make(InfrastructureRoutes::class)->register();

        if ($descriptor->routes->auto) {
            $this->app->make(ContentRoutes::class)->register();
        }
    }

    /**
     * A project redefines one with its own `RateLimiter::for()`, which boots later.
     */
    private function defineRateLimiters(): void
    {
        if ($this->app['config']->get('drupal-backend.content_types') === []) {
            return;
        }

        $limits = $this->app->make(Descriptor::class)->limits;

        RateLimiter::for('content', fn (Request $request) => Limit::perMinute($limits->content)->by($request->ip()));
        RateLimiter::for('search-suggest', fn (Request $request) => Limit::perMinute($limits->searchSuggest)->by($request->ip()));
        RateLimiter::for('drupal-webhook', fn (Request $request) => Limit::perMinute($limits->drupalWebhook)->by($request->ip()));
    }
}
