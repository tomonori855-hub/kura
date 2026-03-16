<?php

return [
    /*
     * APCu key prefix.
     */
    'prefix' => 'kura',

    /*
     * TTL in seconds per cache type.
     * ids is shortest — its expiry triggers rebuild.
     */
    'ttl' => [
        'ids' => 3600,
        'meta' => 4800,
        'record' => 4800,
        'index' => 4800,
        'ids_jitter' => 600,  // random 0–600s added to ids TTL to prevent thundering herd
    ],

    /*
     * Chunk size for index splitting (number of unique values per chunk).
     * null = no chunking.
     */
    'chunk_size' => null,

    /*
     * Rebuild lock TTL in seconds.
     * Set to 1.5–2x the expected Loader execution time.
     */
    'lock_ttl' => 60,

    /*
     * Rebuild strategy.
     *
     * 'sync'     — synchronous rebuild (no queue needed)
     * 'queue'    — async rebuild via queue job
     * 'callback' — custom callback (register via ServiceProvider)
     */
    'rebuild' => [
        'strategy' => 'sync',
        'queue' => [
            'connection' => null,
            'queue' => null,
            'retry' => 3,
        ],
    ],

    /*
     * Reference data version resolution.
     *
     * driver:    'database' — resolve from DB table
     * table:     DB table name for version records
     * columns:   column name mapping
     * cache_ttl: seconds to cache the resolved version in APCu (0 = no cache)
     */
    'version' => [
        'driver' => 'database',       // 'database' or 'csv'
        'table' => 'reference_versions',
        'columns' => [
            'version' => 'version',
            'activated_at' => 'activated_at',
        ],
        'csv_path' => '',              // path to versions.csv (for 'csv' driver)
        'cache_ttl' => 300,            // seconds to cache in APCu (0 = no cache)
    ],

    /*
     * Cache warm endpoint.
     *
     * enabled: register the POST /kura/warm route
     * token:   Bearer token for authentication (required)
     * path:    URL path (default: kura/warm)
     */
    'warm' => [
        'enabled' => false,
        'token' => env('KURA_WARM_TOKEN', ''),
        'path' => 'kura/warm',
    ],

    /*
     * Per-table overrides.
     *
     * 'tables' => [
     *     'products' => [
     *         'ttl' => ['record' => 7200],
     *         'chunk_size' => 10000,
     *     ],
     * ],
     */
    'tables' => [],
];
