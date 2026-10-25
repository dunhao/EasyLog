<?php

namespace EasyLog\Providers;

use Illuminate\Support\ServiceProvider;
use EasyLog\Services\Storage\StorageManager;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('easylog.storage', function ($app) {
            return (new StorageManager(config('storage')))->adapter();
        });
    }
}
