<?php

return [
    // Explicit opt-in: the operator must select imagery authorized for this deployment.
    'satellite' => [
        'enabled' => (bool) env('MAP_SATELLITE_ENABLED', false),
        'url' => env('MAP_SATELLITE_TILE_URL', ''),
        'attribution' => env('MAP_SATELLITE_ATTRIBUTION', ''),
        'maxzoom' => (int) env('MAP_SATELLITE_MAX_ZOOM', 18),
        'tileSize' => (int) env('MAP_SATELLITE_TILE_SIZE', 256),
    ],
];
