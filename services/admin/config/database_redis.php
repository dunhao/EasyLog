<?php

return [
    'client'  => env('REDIS_CLIENT', 'predis'),
    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix'  => env('REDIS_PREFIX', 'easylog_'),
    ],
    'default' => [
        'url'      => env('REDIS_URL'),
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port'     => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],
    'cache' => [
        'url'      => env('REDIS_CACHE_URL'),
        'host'     => env('REDIS_CACHE_HOST', '127.0.0.1'),
        'password' => env('REDIS_CACHE_PASSWORD'),
        'port'     => env('REDIS_CACHE_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],
];
