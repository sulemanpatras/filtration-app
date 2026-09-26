<?php

return [
    'client_id' => env('SHOPIFY_CLIENT_ID', ''),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),
    'app_url' => env('SHOPIFY_APP_URL', env('APP_URL', 'https://subscribe-blinks-doorpost.ngrok-free.dev')),
    'api_version' => env('SHOPIFY_API_VERSION', '2024-04'),
    'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_product_listings,read_collections'),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_CLIENT_SECRET', '')),
];
