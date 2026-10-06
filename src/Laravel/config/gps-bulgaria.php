<?php

declare(strict_types=1);

return [
    /*
    | The tenant used when you call GpsBulgaria::objects() etc. directly.
    */
    'default' => env('GPS_BULGARIA_DEFAULT', 'main'),

    /*
    | One entry per API key known at deploy time. For keys stored per
    | customer in your database, use GpsBulgaria::forKey($key) instead;
    | it inherits base_url/timeout/retry from the default tenant.
    */
    'tenants' => [
        'main' => [
            'api_key' => env('GPS_BULGARIA_API_KEY'),
            'base_url' => env('GPS_BULGARIA_BASE_URL', 'https://iot.gps.bg/api/v2'),
            'timeout' => env('GPS_BULGARIA_TIMEOUT', 30),
            // Total attempts for GET requests on 503 / network errors. 1 = no retry.
            'retry' => env('GPS_BULGARIA_RETRY_ATTEMPTS', 1),
        ],
    ],
];
