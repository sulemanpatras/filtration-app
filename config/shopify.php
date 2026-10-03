<?php

return [

    /*
    | Credentials are injected by `shopify app dev` (SHOPIFY_API_KEY / SHOPIFY_API_SECRET)
    | or can be set manually in .env for production.
    */
    'api_key' => env('SHOPIFY_API_KEY'),

    'api_secret' => env('SHOPIFY_API_SECRET'),

    'scopes' => env('SCOPES', 'read_products,read_themes'),

    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    // Collections with more products than this have native Shopify filters disabled.
    'filter_threshold' => (int) env('SHOPIFY_FILTER_THRESHOLD', 5000),

    // Maximum number of values returned per facet (tags can be huge).
    'facet_limit' => (int) env('SHOPIFY_FACET_LIMIT', 100),

];
