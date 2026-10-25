<?php

namespace EasyLog\Services\Storage;

class StorageManager
{
    private ?StorageAdapterInterface $adapter = null;

    public function __construct(private array $config) {}

    public function adapter(): StorageAdapterInterface
    {
        if ($this->adapter instanceof StorageAdapterInterface) {
            return $this->adapter;
        }
        $driver = $this->config['driver'] ?? 'elasticsearch';
        $config = $this->config['adapters'][$driver] ?? [];
        return $this->adapter = match ($driver) {
            'openobserve' => new OpenObserveAdapter($config),
            'quickwit'    => new QuickwitAdapter($config),
            'sqlite'      => new SqliteStorageAdapter($config),
            default       => new ElasticsearchAdapter($config),
        };
    }
}
