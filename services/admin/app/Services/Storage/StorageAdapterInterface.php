<?php

namespace EasyLog\Services\Storage;

/**
 * 存储后端适配器接口（可切换：Elasticsearch / OpenObserve / Quickwit）
 */
interface StorageAdapterInterface
{
    /**
     * 批量写入日志文档
     *
     * @param string $indexName 索引名（如 easylog-{ws}-{topic}-{yyyymm}）
     * @param array<array<string,mixed>> $docs 日志文档数组
     * @return array{success:int,failed:int,errors:?array}
     */
    public function bulkWrite(string $indexName, array $docs): array;

    /**
     * 检索
     *
     * @param string $indexPattern 索引模式（如 easylog-{ws}-*）
     * @param array<string,mixed> $dsl 转译后的查询 DSL
     * @return array{hits:array,total:int,took_ms:int}
     */
    public function search(string $indexPattern, array $dsl): array;

    /**
     * 聚合分析
     *
     * @param string $indexPattern
     * @param array<string,mixed> $dsl
     * @return array
     */
    public function aggregate(string $indexPattern, array $dsl): array;

    /**
     * 创建索引（带 mapping）
     */
    public function createIndex(string $indexName, array $mapping): void;

    /**
     * 按月滚动索引（用于冷热分离）
     */
    public function ensureMonthlyRoll(string $indexPrefix): void;

    /**
     * 列出前缀匹配的所有索引名
     *
     * @return array<int,string> 索引名列表
     */
    public function listIndices(string $indexPrefix): array;

    /**
     * 删除索引（按保留策略清理）
     */
    public function deleteIndex(string $indexName): void;

    /**
     * 健康检查
     */
    public function ping(): bool;
}
