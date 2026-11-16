<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product image disk
    |--------------------------------------------------------------------------
    |
    | Product images are public content, so the default disk must be served
    | through the storage symlink. This is the only place the disk is read.
    |
    */

    'image_disk' => env('PRODUCT_IMAGES_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Catalog cache (requirement 3)
    |--------------------------------------------------------------------------
    |
    | TTLs in seconds. Writes invalidate by version keys (see CatalogCache), so
    | the TTL is only a safety net and a memory bound. Read here and nowhere
    | else: env() outside config files returns nothing under config:cache.
    |
    */

    'cache' => [
        'list_ttl' => (int) env('PRODUCTS_CACHE_LIST_TTL', 600),
        'detail_ttl' => (int) env('PRODUCTS_CACHE_DETAIL_TTL', 1800),
        'categories_ttl' => (int) env('PRODUCTS_CACHE_CATEGORIES_TTL', 3600),
    ],

];
