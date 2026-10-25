<?php

return [

    // 存储后端驱动：elasticsearch | openobserve | quickwit | sqlite（lite 档）
    'driver' => env('STORAGE_DRIVER', 'elasticsearch'),

    'adapters' => [

        'elasticsearch' => [
            'hosts'        => array_filter(explode(',', (string) env('ES_HOSTS', 'http://localhost:9200'))),
            'username'     => env('ES_USERNAME'),
            'password'     => env('ES_PASSWORD'),
            'api_key'       => env('ES_API_KEY'),
            'ssl_verify'   => (bool) env('ES_SSL_VERIFY', false),
            'index_prefix' => env('ES_INDEX_PREFIX', 'easylog-'),
            'bulk_size'    => (int) env('ES_BULK_SIZE', 1000),
            'refresh'      => env('ES_REFRESH', 'false'), // false | true | wait_for
        ],

        // lite 档：单文件 SQLite，零外部依赖（个人/开发/小团队）
        'sqlite' => [
            'path' => env('SQLITE_LOG_PATH', database_path('easylog-logs.sqlite')),
        ],

        'openobserve' => [
            'url'         => env('OO_URL', 'http://localhost:5080'),
            'org'         => env('OO_ORG', 'easylog'),
            'stream'      => env('OO_STREAM', 'default'),
            'api_key'     => env('OO_API_KEY'),
            // Basic 认证备选（与 api_key 二选一）
            'username'    => env('OO_USERNAME'),
            'password'    => env('OO_PASSWORD'),
            'bulk_size'   => (int) env('OO_BULK_SIZE', 1000),
        ],

        'quickwit' => [
            'url'         => env('QW_URL', 'http://localhost:7280'),
            'index'       => env('QW_INDEX', 'easylog'),
            'bulk_size'   => (int) env('QW_BULK_SIZE', 1000),
        ],
    ],

    // 索引命名：easylog-{workspace_uuid}-{topic_uuid}-{yyyymm}
    'index_template' => '{prefix}{workspace}-{topic}-{yyyymm}',
];
