<?php

return [

    'inventory' => [
        // Units held back from WooCommerce for every SKU that has no buffer of its own yet.
        'default_buffer' => (int) env('B2B_STOCK_BUFFER', 0),
    ],

    'price_check' => [
        // Order-level differences up to this amount (in the order currency) are rounding,
        // e.g. WooCommerce stores 54.1666 where B2B Sync has 54.17. They are saved, not flagged.
        'tolerance' => (float) env('B2B_PRICE_TOLERANCE', 1.00),
    ],

    'alerts' => [
        // Who gets an e-mail when an order gives up or is charged at the wrong price.
        // Comma-separate several addresses. Empty = no alert e-mails.
        'mail_to' => env('B2B_ALERT_EMAIL', ''),
        'max_per_hour' => (int) env('B2B_ALERT_MAX_PER_HOUR', 20),
    ],

    'storefront' => [
        // Seconds the WordPress plugin may cache a customer's prices before asking again.
        'cache_ttl' => (int) env('B2B_STOREFRONT_CACHE_TTL', 300),
    ],

];
