<?php

return [

    // Which ERP the app talks to: "fake" (local development, no external calls) or "fortnox".
    'driver' => env('ERP_DRIVER', 'fake'),

    'fortnox' => [
        'client_id' => env('FORTNOX_CLIENT_ID'),
        'client_secret' => env('FORTNOX_CLIENT_SECRET'),
        // Must match the redirect URI registered for the integration in Fortnox's Developer Portal.
        'redirect_uri' => env('FORTNOX_REDIRECT_URI', 'http://127.0.0.1:8000/fortnox/callback'),
        'scopes' => env('FORTNOX_SCOPES', 'companyinformation customer article order'),

        'auth_url' => 'https://apps.fortnox.se/oauth-v1/auth',
        'token_url' => 'https://apps.fortnox.se/oauth-v1/token',
        'api_url' => env('FORTNOX_API_URL', 'https://api.fortnox.se/3'),

        // Real-time events (php artisan fortnox:listen). Topics need the matching scope.
        'events' => [
            'url' => env('FORTNOX_EVENTS_URL', 'wss://ws.fortnox.se/topics-v1'),
            'topics' => env('FORTNOX_EVENT_TOPICS', 'articles'),
        ],
        'timeout' => (int) env('FORTNOX_TIMEOUT', 20),
    ],

];
