# Drupal backend for Laravel

A Laravel package for consuming a [Drupal](https://www.drupal.org/) JSON:API backend. It gives a Laravel frontend a descriptor-driven content layer: you describe your Drupal content once, and the package fetches, maps, caches, lists, searches and renders it.

Drupal stays the content store; Laravel serves the site. The package contains nothing specific to one site, so the same code can power any number of Drupal-backed frontends.

## Requirements

- PHP ^8.4
- Laravel ^13.0
- Livewire ^4.3
- Optional: [`ralphjsmit/laravel-seo`](https://github.com/ralphjsmit/laravel-seo), used to build page metadata from the descriptor's `seo` map

## Installation

The consuming project must point Composer at the GitHub repositories. Add them to the project's `composer.json`. If you want to also install the docs package (see [Documentation](#documentation) below) add its own entry:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/proteo/drupal-backend" },
        { "type": "vcs", "url": "https://github.com/proteo/drupal-backend-docs" }
    ]
}
```

Then require the package(s):

```bash
composer require proteo/drupal-backend
composer require proteo/drupal-backend-docs
```

The service provider is registered through Laravel package auto-discovery. Publish the config, then declare your Drupal sources and content types in `config/drupal-backend.php` (the descriptor):

```bash
php artisan vendor:publish --tag=drupal-backend-config
```

With no content types declared, the package stays inert.

## Main features

- **Descriptor-driven content model.** Describe Drupal sources, content types, fields, relations, filters and sorts in a single config file. Views and components only see frontend field names, never Drupal paths. Raw values (such as `env()` calls) are accepted; the package trims, casts and applies defaults itself.
- **JSON:API client.** Talks to one or more Drupal backends ("sources"), resolves path aliases, and maps responses to immutable entities. Includes images, files and media chains.
- **Item pages and listings.** Ready-made routes and Blade views for index, listing, item and static pages, with Livewire-powered listings.
- **Advanced search.** Tabbed, paginated search per searchable content type, with suggestions, highlighting, filters (equals, year range, boolean) and sorts. The search backend is swappable and declares its capabilities; the default answers from native JSON:API collections.
- **Related content.** A `<x-drupal::related>` component and related-content strategies that find entities sharing criteria with a source entity, with an optional slot to customise rendering.
- **Caching with a stale window.** Content is cached with a fresh and a stale window (5 minutes and 24 hours by default). If a refresh fails inside the stale window, the stale copy keeps being served. Cache invalidation is a single content-generation counter, per source.
- **Webhook and cache flushing.** A signed webhook lets Drupal invalidate cached content, and `drupal:flush` clears it from the console.
- **Entity decorators and extras.** Per-type decorators compute extra values for a batch of entities, and projects can supply their own entity subclass to add presentation helpers.
- **SEO friendly.** Page metadata built from the descriptor's `seo` map when `ralphjsmit/laravel-seo` is installed.
- **Validation and testing tools.** `drupal:validate` checks the descriptor against the live backend, and the package ships `FakesDrupal` and `DescriptorSmokeTests` helpers for testing a consuming project without a real Drupal.
- **Resilient failure handling.** Upstream problems map to clear outcomes: not found (404), unavailable (503 with `Retry-After`), and bad response (502). Visitors see generic messages; upstream detail goes to the logs.

## Documentation

The developer documentation lives in a separate companion project, **[`proteo/drupal-backend-docs`](https://github.com/proteo/drupal-backend-docs)**. Can be installed as a dev dependency and your application will serve it at `/backend-docs`.

```bash
composer require --dev proteo/drupal-backend-docs
```

Source and issues: <https://github.com/proteo/drupal-backend-docs>

## Development

```bash
composer test   # run the PHPUnit suite
composer lint   # check code style with Pint
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contribution workflow, and [CHANGELOG.md](CHANGELOG.md) for release history. The package follows [Semantic Versioning](https://semver.org/).

## License

GNU General Public License v3.0.
