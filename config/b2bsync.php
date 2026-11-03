<?php

return [

    'inventory' => [
        // Units held back from WooCommerce for every SKU that has no buffer of its own yet.
        'default_buffer' => (int) env('B2B_STOCK_BUFFER', 0),
    ],

];
