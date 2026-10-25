<?php

return [

    // 默认数据库连接（元数据存储）
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [

        'sqlite' => [
            'driver'         => 'sqlite',
            'url'            => env('DB_URL'),
            'database'       => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'         => env('DB_PREFIX', ''),
            'foreign_key_constraints' => true,
        ],

        'mysql' => [
            'driver'         => 'mysql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'easylog'),
            'username'       => env('DB_USERNAME', 'easylog'),
            'password'       => env('DB_PASSWORD', 'secret'),
            'unix_socket'    => env('DB_SOCKET'),
            'charset'        => env('DB_CHARSET', 'utf8mb4'),
            'collation'      => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'         => env('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
            'options'        => array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('DB_SSL_CA') ?? false,
            ]),
        ],
    ],
    'migrations' => [
        'table'  => 'migrations',
        'update_date_on_publish' => true,
    ],
];
