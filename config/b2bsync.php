<?php

return [

    'inventory' => [
        // Units held back from WooCommerce for every SKU that has no buffer of its own yet.
        'default_buffer' => (int) env('B2B_STOCK_BUFFER', 0),
    ],

    'storefront' => [
        // Seconds the WordPress plugin may cache a customer's prices before asking again.
        'cache_ttl' => (int) env('B2B_STOREFRONT_CACHE_TTL', 300),
    ],

];
