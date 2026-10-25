<?php

namespace EasyLog\Services\Storage;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenObserve 适配器（成本优先场景）
 *
 * 对接 OpenObserve HTTP API（v0.10+）：
 *   - 写入：POST /api/{org}/{stream}/_json（JSON 数组）
 *   - 检索：POST /api/{org}/{stream}/_search（SQL 查询引擎）
 *   - 流列表：GET /api/{org}/streams；删除：DELETE /api/{org}/streams/{stream}
 *
 * 本平台查询 DSL 来自 QueryTranslator，节点集有限
 * （term/match/wildcard/range/bool/match_all + histogram/terms/指标聚合），
 * 由 buildWhere()/aggToSql() 反向转译为 OpenObserve SQL。
 *
 * 时间约定：写入时把 doc 的 ts（ISO8601）换算注入 _timestamp（epoch 微秒），
 * 查询时 range ts → _timestamp 微秒区间（OpenObserve 的时间字段）。
 */
class OpenObserveAdapter implements StorageAdapterInterface
{
    private string $url;
    private string $org;
    private string $apiKey;
    private string $username;
    private string $password;
    private int $bulkSize;

    public function __construct(array $config)
    {
        $this->url       = rtrim((string) ($config['url'] ?? 'http://localhost:5080'), '/');
        $this->org       = (string) ($config['org'] ?? 'easylog');
        $this->apiKey    = (string) ($config['api_key'] ?? '');
        $this->username  = (string) ($config['username'] ?? '');
        $this->password  = (string) ($config['password'] ?? '');
        $this->bulkSize  = max(1, (int) ($config['bulk_size'] ?? 1000));
    }

    public function bulkWrite(string $indexName, array $docs): array
    {
        if (empty($docs)) {
            return ['success' => 0, 'failed' => 0, 'errors' => null];
        }

        $stream = self::toStreamName($indexName);
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach (array_chunk($docs, $this->bulkSize) as $chunk) {
            $body = array_map(fn (array $doc) => $this->injectTimestamp($doc), array_values($chunk));
            try {
                $response = $this->http()->post($this->url . "/api/{$this->org}/{$stream}/_json", $body);
                if ($response->successful()) {
                    $result = $response->json();
                    $chunkFailed = (int) ($result['failed'] ?? 0);
                    $success += count($body) - $chunkFailed;
                    $failed += $chunkFailed;
                    if ($chunkFailed > 0) {
                        $errors[] = $result['status'] ?? 'partial failure';
                    }
                } else {
                    $failed += count($body);
                    $errors[] = 'HTTP ' . $response->status();
                }
            } catch (\Throwable $e) {
                $failed += count($body);
                $errors[] = $e->getMessage();
                Log::error('EasyLog OO bulk write failed', ['stream' => $stream, 'error' => $e->getMessage()]);
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $failed ? $errors : null];
    }

    public function search(string $indexPattern, array $dsl): array
    {
        try {
            $result = $this->query($indexPattern, $dsl, 0);
            return [
                'hits'    => $result['hits'] ?? [],
                'total'   => $result['total'] ?? 0,
                'took_ms' => $result['took_ms'] ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::error('EasyLog OO search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0, 'took_ms' => 0];
        }
    }

    public function aggregate(string $indexPattern, array $dsl): array
    {
        try {
            return $this->query($indexPattern, $dsl, 1);
        } catch (\Throwable $e) {
            Log::error('EasyLog OO aggregate failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function createIndex(string $indexName, array $mapping): void
    {
        // OpenObserve 按写入自动建流，无需预建
    }

    public function ensureMonthlyRoll(string $indexPrefix): void
    {
        // 同上
    }

    public function listIndices(string $indexPrefix): array
    {
        try {
            $response = $this->http()->get($this->url . "/api/{$this->org}/streams");
            if (!$response->successful()) {
                return [];
            }
            $streams = $response->json('list') ?? [];
            $prefix = self::toStreamName($indexPrefix);
            $out = [];
            foreach ($streams as $s) {
                $name = (string) ($s['name'] ?? '');
                if ($name !== '' && str_starts_with($name, $prefix)) {
                    $out[] = $name;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EasyLog OO listIndices failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            $this->http()->delete($this->url . "/api/{$this->org}/streams/" . self::toStreamName($indexName));
        } catch (\Throwable $e) {
            Log::warning('EasyLog OO deleteIndex failed', ['error' => $e->getMessage()]);
        }
    }

    public function ping(): bool
    {
        try {
            return $this->http()->get($this->url . '/healthz')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------- 查询执行 ----------

    /**
     * 执行一次 _search；$mode 0 = 明细，1 = 聚合。
     * 返回 ['hits'=>..,'total'=>..,'took_ms'=>..]（明细）或聚合结构（聚合）。
     */
    private function query(string $indexPattern, array $dsl, int $mode): array
    {
        $stream = self::toStreamName($indexPattern);
        [$sql, $size] = $this->buildSql($dsl, $mode);

        $body = [
            'query' => [
                'start_time' => $this->startMicros($dsl),
                'end_time'   => $this->endMicros($dsl),
                'sql'        => $sql,
                'from'       => 0,
                'size'       => $size,
            ],
        ];

        $response = $this->http()->post($this->url . "/api/{$this->org}/{$stream}/_search", $body);
        if (!$response->successful()) {
            throw new \RuntimeException('OO search HTTP ' . $response->status() . ': ' . substr((string) $response->body(), 0, 200));
        }

        $result = $response->json();
        if ($mode === 0) {
            return [
                'hits'    => $result['hits'] ?? [],
                'total'   => (int) ($result['total'] ?? count($result['hits'] ?? [])),
                'took_ms' => (int) round(($result['took'] ?? 0) / 1000),
            ];
        }

        // 聚合：按 dsl.aggs 的键名映射回本平台的聚合结构（SearchController 依赖）
        $rows = $result['hits'] ?? [];
        $out = [];
        foreach (array_keys($dsl['aggs'] ?? ['result' => []]) as $key) {
            if (isset($rows[0]['value']) && count($rows) === 1) {
                // 标量聚合（avg/sum/max/min/cardinality）
                $out[$key] = ['value' => $rows[0]['value'] ?? null];
            } else {
                $out[$key] = ['buckets' => $this->rowsToBuckets($rows)];
            }
        }
        return $out;
    }

    /**
     * DSL → OpenObserve SQL，返回 [sql, size]
     *
     * @return array{0:string,1:int}
     */
    private function buildSql(array $dsl, int $mode): array
    {
        $stream = 'stream'; // SQL 中的表名由 API 路径决定，占位即可
        $where = self::buildWhere($dsl['query'] ?? []);

        $aggs = $dsl['aggs'] ?? [];
        $aggKey = array_key_first($aggs);
        $aggDef = $aggs[$aggKey] ?? [];

        if ($mode === 1 && $aggDef) {
            return [$this->aggToSql($aggDef, $stream, $where), 65536];
        }

        $sql = "SELECT * FROM \"{$stream}\"";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }
        $order = $this->orderBySql($dsl['sort'] ?? []);
        if ($order !== '') {
            $sql .= " ORDER BY {$order}";
        }
        $size = (int) ($dsl['size'] ?? 100);
        $from = (int) ($dsl['from'] ?? 0);
        $sql .= " LIMIT {$size}" . ($from > 0 ? " OFFSET {$from}" : '');
        return [$sql, $size];
    }

    private function aggToSql(array $aggDef, string $stream, string $where): string
    {
        $whereSql = $where !== '' ? " WHERE {$where}" : '';

        if (isset($aggDef['date_histogram'])) {
            $conf = $aggDef['date_histogram'];
            $interval = match ($conf['calendar_interval'] ?? '1h') {
                '1m', 'minute' => '1 minute',
                '1d', 'day'    => '1 day',
                default        => '1 hour',
            };
            return "SELECT histogram(_timestamp, '{$interval}') AS ts_bucket, COUNT(*) AS doc_count"
                . " FROM \"{$stream}\"{$whereSql} GROUP BY ts_bucket ORDER BY ts_bucket ASC";
        }

        if (isset($aggDef['terms'])) {
            $field = $aggDef['terms']['field'] ?? 'level';
            $size = (int) ($aggDef['terms']['size'] ?? 10);
            return "SELECT \"{$field}\" AS k, COUNT(*) AS doc_count"
                . " FROM \"{$stream}\"{$whereSql} GROUP BY \"{$field}\" ORDER BY doc_count DESC LIMIT {$size}";
        }

        // avg / sum / max / min / cardinality
        $fn = array_key_first($aggDef);
        $field = $aggDef[$fn]['field'] ?? '*';
        $sqlFn = $fn === 'cardinality' ? 'count(distinct)' : $fn;
        return "SELECT {$sqlFn}(\"{$field}\") AS value FROM \"{$stream}\"{$whereSql}";
    }

    private function orderBySql(array $sort): string
    {
        $parts = [];
        foreach ($sort as $clause) {
            if (!is_array($clause)) continue;
            foreach ($clause as $field => $conf) {
                $dir = strtoupper((string) ($conf['order'] ?? 'desc'));
                $parts[] = "\"{$field}\" {$dir}";
            }
        }
        return implode(', ', $parts);
    }

    // ---------- DSL → SQL WHERE（public static 以便单测） ----------

    /**
     * 把 QueryTranslator 产出的 query DSL 转为 SQL WHERE 子句
     */
    public static function buildWhere(array $query): string
    {
        if (isset($query['bool'])) {
            return self::boolToSql($query['bool']);
        }
        return self::clauseToSql($query);
    }

    private static function boolToSql(array $bool): string
    {
        $parts = [];
        // must 与 filter 语义同为"必须匹配"，一并转 AND
        foreach (array_merge($bool['must'] ?? [], $bool['filter'] ?? []) as $clause) {
            $parts[] = self::clauseToSql($clause);
        }
        if (isset($bool['should'])) {
            $should = array_map(fn ($c) => self::clauseToSql($c), $bool['should']);
            $parts[] = '(' . implode(' OR ', $should) . ')';
        }
        foreach ($bool['must_not'] ?? [] as $clause) {
            $inner = self::clauseToSql($clause);
            $parts[] = $inner !== '' ? "NOT ({$inner})" : '';
        }
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        return implode(' AND ', $parts);
    }

    private static function clauseToSql(array $clause): string
    {
        $type = array_key_first($clause);
        return match ($type) {
            'match_all' => '',
            'term'      => self::termToSql($clause[$type]),
            'match'     => self::matchToSql($clause[$type]),
            'wildcard'  => self::wildcardToSql($clause[$type]),
            'range'     => self::rangeToSql($clause[$type]),
            'terms'     => self::termsToSql($clause[$type]),
            'exists'    => self::existsToSql($clause[$type]),
            'bool'      => self::boolToSql($clause[$type]),
            default     => '',
        };
    }

    private static function termsToSql(array $terms): string
    {
        $field = array_key_first($terms);
        $values = array_map(fn ($v) => self::quote((string) $v), (array) $terms[$field]);
        return "\"{$field}\" IN (" . implode(', ', $values) . ')';
    }

    private static function existsToSql(array $exists): string
    {
        // IS NULL 在 SQL 解析层已归一化为 must_not(exists)，这里恒为正向存在判断
        $field = (string) $exists['field'];
        return "\"{$field}\" IS NOT NULL";
    }

    private static function termToSql(array $term): string
    {
        $field = array_key_first($term);
        $value = (string) $term[$field];
        if ($field === 'ts') {
            return self::tsRange($value, $value);
        }
        return "\"{$field}\" = " . self::quote($value);
    }

    private static function matchToSql(array $match): string
    {
        $field = array_key_first($match);
        $conf = $match[$field];
        $value = is_array($conf) ? (string) ($conf['query'] ?? '') : (string) $conf;
        return "\"{$field}\" LIKE " . self::quote('%' . $value . '%');
    }

    private static function wildcardToSql(array $wildcard): string
    {
        $field = array_key_first($wildcard);
        $value = (string) ($wildcard[$field]['value'] ?? '');
        $like = str_replace(['*', '?'], ['%', '_'], $value);
        return "\"{$field}\" LIKE " . self::quote($like);
    }

    private static function rangeToSql(array $range): string
    {
        $field = array_key_first($range);
        $conf = $range[$field];
        if ($field === 'ts') {
            return self::tsRange(
                isset($conf['gte']) ? (string) $conf['gte'] : null,
                isset($conf['lte']) ? (string) $conf['lte'] : null,
            );
        }
        $parts = [];
        foreach (['>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte'] as $op => $key) {
            if (isset($conf[$key])) {
                $parts[] = "\"{$field}\" {$op} " . self::quote((string) $conf[$key]);
            }
        }
        return implode(' AND ', $parts);
    }

    /** ts（ISO8601）→ _timestamp 微秒区间 */
    private static function tsRange(?string $from, ?string $to): string
    {
        $parts = [];
        if ($from) {
            $parts[] = '_timestamp >= ' . self::toMicros($from);
        }
        if ($to) {
            $parts[] = '_timestamp <= ' . self::toMicros($to);
        }
        return implode(' AND ', $parts);
    }

    public static function toMicros(string $iso): int
    {
        try {
            return (int) (new \DateTimeImmutable($iso))->format('Uu');
        } catch (\Exception) {
            return 0;
        }
    }

    private static function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }

    // ---------- 写入侧时间字段 ----------

    private function injectTimestamp(array $doc): array
    {
        $micros = isset($doc['ts']) && is_string($doc['ts'])
            ? self::toMicros($doc['ts'])
            : (int) (microtime(true) * 1_000_000);
        if ($micros <= 0) {
            $micros = (int) (microtime(true) * 1_000_000);
        }
        $doc['_timestamp'] = $micros;
        return $doc;
    }

    private function startMicros(array $dsl): int
    {
        foreach ($dsl['query']['bool']['filter'] ?? [] as $clause) {
            if (isset($clause['range']['ts']['gte'])) {
                return self::toMicros((string) $clause['range']['ts']['gte']);
            }
        }
        return (int) ((microtime(true) - 3600) * 1_000_000);
    }

    private function endMicros(array $dsl): int
    {
        foreach ($dsl['query']['bool']['filter'] ?? [] as $clause) {
            if (isset($clause['range']['ts']['lte'])) {
                return self::toMicros((string) $clause['range']['ts']['lte']);
            }
        }
        return (int) (microtime(true) * 1_000_000);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::acceptJson()->timeout(30);
        if ($this->apiKey !== '') {
            $req = $req->withToken($this->apiKey);
        } elseif ($this->username !== '') {
            $req = $req->withBasicAuth($this->username, $this->password);
        }
        return $req;
    }

    /** 索引名/模式 → OpenObserve 流名（连字符转下划线，去通配符） */
    public static function toStreamName(string $indexOrPattern): string
    {
        $name = str_replace('*', '', $indexOrPattern);
        return str_replace('-', '_', $name);
    }

    /** 行集 → 桶集（terms / histogram 通用） */
    private function rowsToBuckets(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            if (isset($row['ts_bucket'])) {
                $buckets[] = ['key_as_string' => (string) $row['ts_bucket'], 'doc_count' => (int) ($row['doc_count'] ?? 0)];
            } elseif (array_key_exists('k', $row)) {
                $buckets[] = ['key' => (string) ($row['k'] ?? ''), 'doc_count' => (int) ($row['doc_count'] ?? 0)];
            }
        }
        return $buckets;
    }
}
