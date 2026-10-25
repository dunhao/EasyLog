<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 默认邮件发送器
    |--------------------------------------------------------------------------
    |
    | 生产/局域网部署用 smtp 并配置下方 mailers.smtp；
    | 开发/测试环境可用 log（邮件内容写入日志，不真正发送）。
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport'    => 'smtp',
            'scheme'       => env('MAIL_SCHEME', null),
            'url'          => env('MAIL_URL'),
            'host'         => env('MAIL_HOST', '127.0.0.1'),
            'port'         => env('MAIL_PORT', 2525),
            'username'     => env('MAIL_USERNAME'),
            'password'     => env('MAIL_PASSWORD'),
            'timeout'      => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport'  => 'failover',
            'mailers'    => [
                'smtp',
                'log',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 全局发件地址
    |--------------------------------------------------------------------------
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'easylog@localhost'),
        'name'    => env('MAIL_FROM_NAME', 'EasyLog'),
    ],

];
