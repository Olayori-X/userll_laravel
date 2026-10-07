<?php

// Which websites may call this API from a browser. Set CORS_ALLOWED_ORIGINS in .env to a comma-separated
// list (for example "https://userll.com,https://www.userll.com"). With nothing set, no website is allowed.
// The Flutter app is not a browser and is not affected by CORS.
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', ''))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    // The API authenticates with a bearer token in a header, not with cookies.
    'supports_credentials' => false,
];