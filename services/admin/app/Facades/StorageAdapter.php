<?php

namespace EasyLog\Facades;

use Illuminate\Support\Facades\Facade;
use EasyLog\Services\Storage\StorageAdapterInterface;

/**
 * StorageAdapter Facade
 *
 * @method static array bulkWrite(string $indexName, array $docs)
 * @method static array search(string $indexPattern, array $dsl)
 * @method static array aggregate(string $indexPattern, array $dsl)
 * @method static void  createIndex(string $indexName, array $mapping)
 * @method static void  ensureMonthlyRoll(string $indexPrefix)
 * @method static void  deleteIndex(string $indexName)
 * @method static bool  ping()
 * @see \EasyLog\Services\Storage\StorageAdapterInterface
 */
class StorageAdapter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'easylog.storage';
    }
}
