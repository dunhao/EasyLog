<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    // 应用名（用于通知、邮件、文档）
    'name' => env('APP_NAME', 'EasyLog'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
    'locale' => env('APP_LOCALE', 'zh_CN'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'zh_CN'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => array_values(array_filter(
        array_map(
            fn (string $key) => trim($key),
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
        fn (string $key) => $key !== ''
    )),
    'maintenance' => [
        'driver' => 'file',
        'store'  => 'database',
    ],
    'providers' => ServiceProvider::defaultProviders()->merge([
        // EasyLog 自有服务提供者
        \EasyLog\Providers\EasyLogServiceProvider::class,
        \EasyLog\Providers\StorageServiceProvider::class,
        \EasyLog\Providers\MessageQueueServiceProvider::class,
        // 第三方
        \Spatie\Permission\PermissionServiceProvider::class,
        \Laravel\Reverb\ReverbServiceProvider::class,
        \Laravel\Sanctum\SanctumServiceProvider::class,
    ])->toArray(),
    'aliases' => Facade::defaultAliases()->merge([
        'StorageAdapter' => \EasyLog\Facades\StorageAdapter::class,
        'MessageQueue'   => \EasyLog\Facades\MessageQueue::class,
        'MQ'             => \EasyLog\Facades\MessageQueue::class, // alias
    ])->toArray(),
];
