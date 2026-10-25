<?php

namespace EasyLog\Services\Storage;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Quickwit 适配器（高吞吐低成本场景）
 *
 * 对接 Quickwit HTTP API（v0.8+）：
 *   - 建索引：POST /api/v1/indexes（dynamic doc mapping，时间字段 _ts = epoch 秒）
 *   - 写入：  POST /api/v1/{index}/ingest（NDJSON）
 *   - 检索：  POST /api/v1/{index}/search（query string + ES 风格聚合透传）
 *   - 索引：  GET /api/v1/indexes、DELETE /api/v1/indexes/{id}
 *
 * Quickwit 的查询语法（field:value、AND/OR/NOT、通配符）与 Lucene 同源，
 * 本平台 QueryTranslator 的受限 DSL 由 buildQueryString() 反向转译。
 * 时间过滤不走查询串，用 start_timestamp / end_timestamp（epoch 秒）。
 */
class QuickwitAdapter implements StorageAdapterInterface
{
    private string $url;
    private string $indexPrefix;
    private int $bulkSize;

    /** @var array<string,bool> 已确认存在的索引 */
    private array $knownIndices = [];

    public function __construct(array $config)
    {
        $this->url         = rtrim((string) ($config['url'] ?? 'http://localhost:7280'), '/');
        $this->indexPrefix = (string) ($config['index'] ?? 'easylog');
        $this->bulkSize    = max(1, (int) ($config['bulk_size'] ?? 1000));
    }

    public function bulkWrite(string $indexName, array $docs): array
    {
        if (empty($docs)) {
            return ['success' => 0, 'failed' => 0, 'errors' => null];
        }

        $this->ensureIndex($indexName);

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach (array_chunk($docs, $this->bulkSize) as $chunk) {
            $ndjson = implode("\n", array_map(
                fn (array $doc) => json_encode(
                    $this->injectTimestamp($doc),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                array_values($chunk)
            ));
            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json-ndjson'])
                    ->timeout(30)
                    ->withBody($ndjson, 'application/json-ndjson')
                    ->post($this->url . "/api/v1/{$indexName}/ingest");
                if ($response->successful()) {
                    $report = $response->json('report') ?? [];
                    $chunkFailed = (int) ($report['failed_docs'] ?? 0);
                    $success += count($chunk) - $chunkFailed;
                    $failed += $chunkFailed;
                    if ($chunkFailed > 0) {
                        $errors[] = $report['errors'] ?? 'partial failure';
                    }
                } else {
                    $failed += count($chunk);
                    $errors[] = 'HTTP ' . $response->status();
                }
            } catch (\Throwable $e) {
                $failed += count($chunk);
                $errors[] = $e->getMessage();
                Log::error('EasyLog QW bulk write failed', ['index' => $indexName, 'error' => $e->getMessage()]);
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $failed ? $errors : null];
    }

    public function search(string $indexPattern, array $dsl): array
    {
        try {
            $result = $this->doSearch($indexPattern, $dsl, 0);
            return [
                'hits'    => $result['hits'] ?? [],
                'total'   => (int) ($result['num_hits'] ?? 0),
                'took_ms' => (int) round(($result['elapsed_time_micros'] ?? 0) / 1000),
            ];
        } catch (\Throwable $e) {
            Log::error('EasyLog QW search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0, 'took_ms' => 0];
        }
    }

    public function aggregate(string $indexPattern, array $dsl): array
    {
        try {
            $result = $this->doSearch($indexPattern, $dsl, 1);
            return $result['aggregations'] ?? [];
        } catch (\Throwable $e) {
            Log::error('EasyLog QW aggregate failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function createIndex(string $indexName, array $mapping): void
    {
        try {
            // dynamic doc mapping：任意字段自动映射；_ts（epoch 秒）为时间字段
            $body = [
                'version' => '0.8',
                'index_id' => $indexName,
                'doc_mapping' => [
                    'mode' => 'dynamic',
                    'timestamp_field' => '_ts',
                ],
                'search_settings' => [
                    'default_search_fields' => ['msg'],
                ],
                'indexing_settings' => [
                    'timestamp_field' => '_ts',
                    'commit_timeout_secs' => 5,
                ],
            ];
            $response = Http::acceptJson()->timeout(30)
                ->post($this->url . '/api/v1/indexes', $body);
            if ($response->successful() || $response->status() === 400) {
                // 400 = index already exists，视为成功
                $this->knownIndices[$indexName] = true;
            }
        } catch (\Throwable $e) {
            Log::warning('EasyLog QW createIndex failed', ['index' => $indexName, 'error' => $e->getMessage()]);
        }
    }

    public function ensureMonthlyRoll(string $indexPrefix): void
    {
        // Quickwit 写入时 ensureIndex 按需创建
    }

    public function listIndices(string $indexPrefix): array
    {
        try {
            $response = Http::acceptJson()->timeout(30)->get($this->url . '/api/v1/indexes');
            if (!$response->successful()) {
                return [];
            }
            $indexes = $response->json('indexes') ?? [];
            $prefix = rtrim($indexPrefix, '*');
            $out = [];
            foreach ($indexes as $idx) {
                $id = (string) ($idx['index_id'] ?? '');
                if ($id !== '' && str_starts_with($id, $prefix)) {
                    $out[] = $id;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EasyLog QW listIndices failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            Http::acceptJson()->timeout(30)->delete($this->url . "/api/v1/indexes/{$indexName}");
            unset($this->knownIndices[$indexName]);
        } catch (\Throwable $e) {
            Log::warning('EasyLog QW deleteIndex failed', ['error' => $e->getMessage()]);
        }
    }

    public function ping(): bool
    {
        try {
            return Http::timeout(10)->get($this->url . '/health/readyz')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------- 查询执行 ----------

    private function doSearch(string $indexPattern, array $dsl, int $mode): array
    {
        $index = rtrim($indexPattern, '*');
        $body = [
            'query' => self::buildQueryString($dsl['query'] ?? []),
            'max_hits' => $mode === 1 ? 0 : (int) ($dsl['size'] ?? 100),
        ];

        // 时间过滤走原生时间字段（epoch 秒）
        [$start, $end] = $this->timeBounds($dsl);
        if ($start !== null) $body['start_timestamp'] = $start;
        if ($end !== null) $body['end_timestamp'] = $end;

        if ($mode === 0) {
            $body['sort_by'] = $this->sortBy($dsl['sort'] ?? []);
            if (isset($dsl['from']) && (int) $dsl['from'] > 0) {
                $body['start_offset'] = (int) $dsl['from'];
            }
            if (isset($dsl['_source'])) {
                $body['output'] = ['field_format' => 'json'];
            }
        } else {
            $body['aggs'] = $dsl['aggs'] ?? [];
        }

        $response = Http::acceptJson()->timeout(30)
            ->post($this->url . "/api/v1/{$index}/search", $body);
        if (!$response->successful()) {
            throw new \RuntimeException('QW search HTTP ' . $response->status() . ': ' . substr((string) $response->body(), 0, 200));
        }
        return $response->json() ?? [];
    }

    // ---------- DSL → Quickwit 查询串（public static 以便单测） ----------

    /**
     * 把 QueryTranslator 产出的 query DSL 转为 Quickwit query string
     */
    public static function buildQueryString(array $query): string
    {
        if (isset($query['bool'])) {
            return self::boolToQueryString($query['bool']);
        }
        return self::clauseToQueryString($query);
    }

    private static function boolToQueryString(array $bool): string
    {
        $parts = [];
        // must 与 filter 一并转 AND（ts 范围在 rangeToQw 里返回空串，由 start/end_timestamp 承担）
        foreach (array_merge($bool['must'] ?? [], $bool['filter'] ?? []) as $clause) {
            $s = self::clauseToQueryString($clause);
            if ($s !== '') $parts[] = $s;
        }
        if (isset($bool['should'])) {
            $should = array_filter(array_map(fn ($c) => self::clauseToQueryString($c), $bool['should']));
            $parts[] = '(' . implode(' OR ', $should) . ')';
        }
        foreach ($bool['must_not'] ?? [] as $clause) {
            $s = self::clauseToQueryString($clause);
            if ($s !== '') $parts[] = 'NOT (' . $s . ')';
        }
        return implode(' AND ', array_filter($parts));
    }

    private static function clauseToQueryString(array $clause): string
    {
        $type = array_key_first($clause);
        return match ($type) {
            'match_all' => '*',
            'term'      => self::termToQw($clause[$type]),
            'match'     => self::matchToQw($clause[$type]),
            'wildcard'  => self::wildcardToQw($clause[$type]),
            'range'     => self::rangeToQw($clause[$type]),
            'terms'     => self::termsToQw($clause[$type]),
            'exists'    => self::existsToQw($clause[$type]),
            'bool'      => self::boolToQueryString($clause[$type]),
            default     => '',
        };
    }

    private static function termsToQw(array $terms): string
    {
        $field = array_key_first($terms);
        $values = array_map(fn ($v) => self::quoteValue((string) $v), (array) $terms[$field]);
        return $field . ':(' . implode(' OR ', $values) . ')';
    }

    private static function existsToQw(array $exists): string
    {
        // IS NULL 在 SQL 解析层已归一化为 must_not(exists)，这里恒为正向存在判断
        return (string) $exists['field'] . ':*';
    }

    private static function termToQw(array $term): string
    {
        $field = array_key_first($term);
        return $field . ':' . self::quoteValue((string) $term[$field]);
    }

    private static function matchToQw(array $match): string
    {
        $field = array_key_first($match);
        $conf = $match[$field];
        $value = is_array($conf) ? (string) ($conf['query'] ?? '') : (string) $conf;
        return $field . ':' . self::quoteValue($value);
    }

    private static function wildcardToQw(array $wildcard): string
    {
        $field = array_key_first($wildcard);
        $value = (string) ($wildcard[$field]['value'] ?? '');
        return $field . ':' . $value; // 通配符值直接透传（* ? 与 Quickwit 语法一致）
    }

    private static function rangeToQw(array $range): string
    {
        $field = array_key_first($range);
        if ($field === 'ts') {
            return ''; // 时间范围走 start/end_timestamp，不进查询串
        }
        $conf = $range[$field];
        $parts = [];
        foreach (['>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte'] as $op => $key) {
            if (isset($conf[$key])) {
                $parts[] = $field . $op . self::quoteValue((string) $conf[$key]);
            }
        }
        return implode(' AND ', $parts);
    }

    private static function quoteValue(string $v): string
    {
        if (strpbrk($v, " \t\"():") !== false) {
            return '"' . str_replace('"', '\\"', $v) . '"';
        }
        return $v;
    }

    // ---------- 时间与排序 ----------

    /** @return array{0:?int,1:?int} epoch 秒 */
    private function timeBounds(array $dsl): array
    {
        $start = null;
        $end = null;
        foreach ($dsl['query']['bool']['filter'] ?? [] as $clause) {
            if (isset($clause['range']['ts'])) {
                if (isset($clause['range']['ts']['gte'])) {
                    $start = self::toEpochSecs((string) $clause['range']['ts']['gte']);
                }
                if (isset($clause['range']['ts']['lte'])) {
                    $end = self::toEpochSecs((string) $clause['range']['ts']['lte']);
                }
            }
        }
        return [$start, $end];
    }

    public static function toEpochSecs(string $iso): int
    {
        try {
            return (int) (new \DateTimeImmutable($iso))->format('U');
        } catch (\Exception) {
            return 0;
        }
    }

    /** ES 风格 sort → Quickwit "ts:desc,level:asc" */
    private function sortBy(array $sort): string
    {
        $parts = [];
        foreach ($sort as $clause) {
            if (!is_array($clause)) continue;
            foreach ($clause as $field => $conf) {
                $dir = strtolower((string) ($conf['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
                $parts[] = "{$field}:{$dir}";
            }
        }
        return implode(',', $parts);
    }

    // ---------- 写入侧时间字段与索引管理 ----------

    private function injectTimestamp(array $doc): array
    {
        $secs = isset($doc['ts']) && is_string($doc['ts'])
            ? self::toEpochSecs($doc['ts'])
            : time();
        if ($secs <= 0) {
            $secs = time();
        }
        $doc['_ts'] = $secs;
        return $doc;
    }

    private function ensureIndex(string $indexName): void
    {
        if (isset($this->knownIndices[$indexName])) {
            return;
        }
        $exists = in_array($indexName, $this->listIndices($this->indexPrefix), true);
        if (!$exists) {
            $this->createIndex($indexName, []);
        }
        $this->knownIndices[$indexName] = true;
    }
}
