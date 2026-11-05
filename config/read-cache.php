<?php

return [
    'enabled' => env('READ_CACHE_ENABLED', env('APP_ENV') !== 'testing'),
    'store' => env('READ_CACHE_STORE', 'file'),
    'version' => env('READ_CACHE_VERSION', 'v1'),
    'ttl' => (int) env('READ_CACHE_TTL', 300),
    'geography_ttl' => (int) env('READ_CACHE_GEOGRAPHY_TTL', 3600),
    'planning_ttl' => (int) env('READ_CACHE_PLANNING_TTL', 30),
];
