<?php

/*
|--------------------------------------------------------------------------
| drupal-backend descriptor
|--------------------------------------------------------------------------
|
| Describes the Drupal content the frontend serves. The package merges only
| the first level of this file into the project's copy, so a project that
| publishes it replaces whole sections; deeper defaults (search limits,
| routes, webhook, rate limits) are applied in code when a key is missing.
|
| With no content types the package stays inert.
*/

return [

    // Bumped when the descriptor format changes incompatibly; boot fails on a mismatch.
    'schema_version' => 3,

    // Declare values raw, e.g. `env('DRUPAL_BASE_URL')`: the package trims, casts and defaults them where it uses them.
    // name => ['base_url', 'jsonapi_path', 'files_path', 'frontend_url', 'webhook' => ['secret', 'tolerance'], 'flush' => ['also' => ['sources' => []]]]
    'sources' => [],

    // Defaults to the source named "default", else the first one.
    'default_source' => null,

    // key => content type; see the package CONTEXT.md for the vocabulary.
    'content_types' => [],

    'search' => [],

    'routes' => [],

    'webhook' => [],

    'limits' => [],

];
