<?php

// Rate limiting for ingestion
return [
    'rate_limits' => [
        'ingest' => [
            'limit' => env('INGEST_RATE_LIMIT', 1000),
            'window' => 1, // 1 秒
        ],
    ],
];
