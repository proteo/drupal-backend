<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Drupal backend
    |--------------------------------------------------------------------------
    |
    | Shared settings for talking to the Drupal JSON:API. The backends themselves are
    | the sources in config/drupal-backend.php.
    */

    // Seconds to wait for a connection and for the whole request.
    'connect_timeout' => (int) env('DRUPAL_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('DRUPAL_TIMEOUT', 10),

    // Extra attempts for GET requests after a connection error or 5xx, and the pause between them.
    'retries' => (int) env('DRUPAL_RETRIES', 2),
    'retry_delay_ms' => (int) env('DRUPAL_RETRY_DELAY_MS', 200),

    // Content cache. Set DRUPAL_CACHE_ENABLED=false in local development to always see current backend content.
    'cache' => [
        'enabled' => (bool) env('DRUPAL_CACHE_ENABLED', true),
        // Seconds an entry is served without revalidation.
        'fresh' => (int) env('DRUPAL_CACHE_FRESH', 300),
        // Seconds past the fresh window that a stale copy is still served (also when Drupal is failing).
        'stale' => (int) env('DRUPAL_CACHE_STALE', 86400),
        // Seconds an unknown alias is remembered as not found.
        'negative' => (int) env('DRUPAL_CACHE_NEGATIVE', 60),
        // Dedicated cache store; null uses the default one.
        'store' => env('DRUPAL_CACHE_STORE'),
    ],

    // Related content sends one request per target type and criterion; a request over this many is a configuration error.
    'related' => [
        'max_requests' => (int) env('DRUPAL_RELATED_MAX_REQUESTS', 12),
    ],

];
