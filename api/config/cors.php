<?php

/*
 * The Vue client is a separate project served from its own origin, so the API
 * must opt in to cross-origin requests. Allowed origins are configurable via
 * FRONTEND_URLS so production does not inherit the dev defaults.
 */
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URLS', 'http://localhost:5173,http://127.0.0.1:5173')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // No cookie-based auth between the two projects; the client is stateless.
    'supports_credentials' => false,
];
