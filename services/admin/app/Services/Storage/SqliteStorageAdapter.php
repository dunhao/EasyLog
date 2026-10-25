<?php

declare(strict_types=1);

namespace EasyLog\Services\Storage;

use EasyLog\Services\Storage\Sqlite\EsDslCompiler;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * SQLite 存储适配器（lite 单机档）
 *
 * 单表设计：所有"索引"（easylog-{ws}-{topic}-{yyyymm}）共用一张
 * easylog_logs 表，index_name 列区分；常用字段（ts_ms/level/msg/
 * workspace_id/topic_id）专列，其余业务字段收进 fields JSON。
 *
 * 检索语义：msg 全文用 LIKE 子串匹配（中文子串天然有效，避免 FTS5
 * 分词与 ES match 语义的偏差）；任意业务字段经 json_extract 寻址。
 * 规模定位：个人/小团队/开发环境（百万行级）；更大规模请切
 * STORAGE_DRIVER=elasticsearch（core 档）。
 */
class SqliteStorageAdapter implements StorageAdapterInterface
{
    private ?PDO $pdo = null;
    private EsDslCompiler $compiler;

    public function __construct(private array $config)
    {
        $this->compiler = new EsDslCompiler();
    }

    public function bulkWrite(string $indexName, array $docs): array
    {
        if ($docs === []) {
            return ['success' => 0, 'failed' => 0, 'errors' => null];
        }
        $this->ensureSchema();

        $success = 0;
        $failed = 0;
        $errors = [];

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO easylog_logs
                    (index_name, ts, ts_ms, level, msg, workspace_id, topic_id, tenant_id, fields, doc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($docs as $doc) {
                $row = $this->toRow($indexName, is_array($doc) ? $doc : []);
                $stmt->execute([
                    $indexName,
                    $row['ts'],
                    $row['ts_ms'],
                    $row['level'],
                    $row['msg'],
                    $row['workspace_id'],
                    $row['topic_id'],
                    $row['tenant_id'],
                    $row['fields'],
                    $row['doc'],
                ]);
                $success++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Log::error('EasyLog sqlite bulk write failed', ['error' => $e->getMessage()]);
            return ['success' => 0, 'failed' => count($docs), 'errors' => [$e->getMessage()]];
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => null];
    }

    public function search(string $indexPattern, array $dsl): array
    {
        $this->ensureSchema();
        $start = microtime(true);

        $compiled = $this->compiler->compileWhere($dsl['query'] ?? []);
        $where = $compiled['where'];
        $bindings = $compiled['bindings'];
        $indexCond = $this->indexCondition($indexPattern, $bindings);

        $pdo = $this->pdo();

        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM easylog_logs WHERE {$indexCond} AND {$where}");
            $countStmt->execute($bindings);
            $total = (int) $countStmt->fetchColumn();

            $size = min(1000, max(1, (int) ($dsl['size'] ?? 100)));
            $offset = max(0, (int) ($dsl['from'] ?? 0));
            $order = $this->compiler->compileSort($dsl['sort'] ?? []);

            $stmt = $pdo->prepare(
                "SELECT doc FROM easylog_logs WHERE {$indexCond} AND {$where} ORDER BY {$order} LIMIT {$size} OFFSET {$offset}"
            );
            $stmt->execute($bindings);

            $sourceFields = $dsl['_source'] ?? null;
            $hits = [];
            while (($raw = $stmt->fetchColumn()) !== false) {
                $doc = json_decode((string) $raw, true);
                if (!is_array($doc)) {
                    continue;
                }
                $hits[] = is_array($sourceFields)
                    ? array_intersect_key($doc, array_flip($sourceFields))
                    : $doc;
            }

            return [
                'hits'    => $hits,
                'total'   => $total,
                'took_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        } catch (\Throwable $e) {
            Log::error('EasyLog sqlite search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0, 'took_ms' => 0];
        }
    }

    public function aggregate(string $indexPattern, array $dsl): array
    {
        $this->ensureSchema();

        $compiled = $this->compiler->compileWhere($dsl['query'] ?? []);
        $where = $compiled['where'];
        $bindings = $compiled['bindings'];
        $indexCond = $this->indexCondition($indexPattern, $bindings);

        $out = [];
        foreach (($dsl['aggs'] ?? []) as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            if (isset($def['date_histogram'])) {
                $out[$name] = $this->dateHistogram($def['date_histogram'], $indexCond, $where, $bindings, $dsl);
            } elseif (isset($def['terms'])) {
                $out[$name] = $this->termsAgg($def, $indexCond, $where, $bindings);
            } else {
                $out[$name] = $this->metricAgg($def, $indexCond, $where, $bindings);
            }
        }
        return $out;
    }

    public function createIndex(string $indexName, array $mapping): void
    {
        // 单表设计：索引即 index_name 取值，无需物理创建
    }

    public function ensureMonthlyRoll(string $indexPrefix): void
    {
        // 单表设计：按月滚动由写入侧 buildIndexName 自然体现
    }

    public function listIndices(string $indexPrefix): array
    {
        $this->ensureSchema();
        $bindings = [];
        $cond = $this->indexCondition($indexPrefix, $bindings);
        $stmt = $this->pdo()->prepare("SELECT DISTINCT index_name FROM easylog_logs WHERE {$cond}");
        $stmt->execute($bindings);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function deleteIndex(string $indexName): void
    {
        $this->ensureSchema();
        try {
            $stmt = $this->pdo()->prepare('DELETE FROM easylog_logs WHERE index_name = ?');
            $stmt->execute([$indexName]);
        } catch (\Throwable $e) {
            Log::error('EasyLog sqlite deleteIndex failed', ['index' => $indexName, 'error' => $e->getMessage()]);
        }
    }

    public function ping(): bool
    {
        try {
            $this->ensureSchema();
            return (bool) $this->pdo()->query('SELECT 1')->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // 聚合实现
    // ------------------------------------------------------------------

    /**
     * date_histogram：按 UTC 桶对齐聚合 + min_doc_count=0 空桶填充
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $dsl
     * @return array{buckets: array<int, array{key_as_string:string, key:int, doc_count:int}>}
     */
    private function dateHistogram(array $def, string $indexCond, string $where, array $bindings, array $dsl): array
    {
        $bucketMs = match ($def['calendar_interval'] ?? '1m') {
            '1h'   => 3_600_000,
            '1d'   => 86_400_000,
            default => 60_000,
        };

        $rows = [];
        try {
            $stmt = $this->pdo()->prepare(
                "SELECT (ts_ms / {$bucketMs}) * {$bucketMs} AS bucket, COUNT(*) AS cnt
                 FROM easylog_logs WHERE {$indexCond} AND {$where}
                 GROUP BY bucket"
            );
            $stmt->execute($bindings);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rows[(int) $row['bucket']] = (int) $row['cnt'];
            }
        } catch (\Throwable $e) {
            Log::error('EasyLog sqlite histogram failed', ['error' => $e->getMessage()]);
            return ['buckets' => []];
        }

        // 空桶填充：时间范围来自 DSL 的 ts range filter（histogram 入口必带 from/to）
        $range = $this->compiler->extractTimeRange($dsl['query'] ?? []);
        $buckets = [];
        if ($range['from_ms'] !== null && $range['to_ms'] !== null && $range['to_ms'] >= $range['from_ms']) {
            $start = intdiv($range['from_ms'], $bucketMs) * $bucketMs;
            for ($t = $start; $t <= $range['to_ms']; $t += $bucketMs) {
                $buckets[] = [
                    'key_as_string' => gmdate('Y-m-d\TH:i:s', intdiv($t, 1000)) . '.000Z',
                    'key'           => $t,
                    'doc_count'     => $rows[$t] ?? 0,
                ];
                // 防御：极端大范围（如 100 年按分钟分桶）截断到 2000 桶
                if (count($buckets) >= 2000) {
                    break;
                }
            }
        } else {
            foreach ($rows as $t => $cnt) {
                $buckets[] = [
                    'key_as_string' => gmdate('Y-m-d\TH:i:s', intdiv($t, 1000)) . '.000Z',
                    'key'           => $t,
                    'doc_count'     => $cnt,
                ];
            }
        }

        return ['buckets' => $buckets];
    }

    /**
     * terms 聚合（可带一层 metric 子聚合）
     *
     * @param array<string,mixed> $def
     * @return array{buckets: array<int, array{key:mixed, doc_count:int, metric?: array{value:mixed}}>}
     */
    private function termsAgg(array $def, string $indexCond, string $where, array $bindings): array
    {
        $terms = $def['terms'] ?? [];
        $field = (string) ($terms['field'] ?? '');
        $size = max(1, (int) ($terms['size'] ?? 10));
        $expr = $this->compiler->fieldExpr($field);
        if ($expr === null) {
            return ['buckets' => []];
        }

        $metricExpr = null;
        $metricFn = null;
        $sub = $def['aggs']['metric'] ?? null;
        if (is_array($sub)) {
            $metricFn = (string) key($sub);
            $metricExpr = $this->compiler->fieldExpr((string) ($sub[$metricFn]['field'] ?? ''));
        }

        $selects = ["{$expr} AS fkey", 'COUNT(*) AS cnt'];
        if ($metricExpr !== null) {
            $selects[] = $this->metricSql($metricFn, $metricExpr);
        }

        try {
            $stmt = $this->pdo()->prepare(
                "SELECT " . implode(', ', $selects) . "
                 FROM easylog_logs WHERE {$indexCond} AND {$where}
                 GROUP BY fkey ORDER BY cnt DESC, fkey LIMIT {$size}"
            );
            $stmt->execute($bindings);

            $buckets = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $bucket = [
                    'key'       => $this->decodeKey($row['fkey']),
                    'doc_count' => (int) $row['cnt'],
                ];
                if ($metricExpr !== null) {
                    $value = $row['mval'];
                    $bucket['metric'] = ['value' => $metricFn === 'cardinality'
                        ? (int) $value
                        : ($value === null ? null : (float) $value)];
                }
                $buckets[] = $bucket;
            }
            return ['buckets' => $buckets];
        } catch (\Throwable $e) {
            Log::error('EasyLog sqlite terms agg failed', ['error' => $e->getMessage()]);
            return ['buckets' => []];
        }
    }

    /**
     * 单值指标聚合：avg / sum / max / min / cardinality / value_count
     *
     * @param array<string,mixed> $def
     * @return array{value: mixed}
     */
    private function metricAgg(array $def, string $indexCond, string $where, array $bindings): array
    {
        $fn = (string) key($def);
        $field = (string) ($def[$fn]['field'] ?? '');
        $expr = $fn === 'value_count' && $field === '_ts'
            ? 'ts_ms'
            : $this->compiler->fieldExpr($field);
        if ($expr === null) {
            return ['value' => null];
        }

        try {
            $stmt = $this->pdo()->prepare(
                "SELECT " . $this->metricSql($fn, $expr) . "
                 FROM easylog_logs WHERE {$indexCond} AND {$where}"
            );
            $stmt->execute($bindings);
            $value = $stmt->fetchColumn();

            if ($fn === 'cardinality' || $fn === 'value_count') {
                return ['value' => (int) $value];
            }
            return ['value' => $value === null ? null : (float) $value];
        } catch (\Throwable $e) {
            Log::error('EasyLog sqlite metric agg failed', ['error' => $e->getMessage()]);
            return ['value' => null];
        }
    }

    /**
     * 指标 SQL 片段，别名固定 mval
     */
    private function metricSql(string $fn, string $expr): string
    {
        return match ($fn) {
            'avg'         => "AVG(CAST({$expr} AS REAL)) AS mval",
            'sum'         => "SUM(CAST({$expr} AS REAL)) AS mval",
            'max'         => "MAX(CAST({$expr} AS REAL)) AS mval",
            'min'         => "MIN(CAST({$expr} AS REAL)) AS mval",
            'cardinality' => "COUNT(DISTINCT {$expr}) AS mval",
            'count', 'value_count' => "COUNT({$expr}) AS mval",
            default       => "COUNT(*) AS mval",
        };
    }

    /**
     * terms 分组键还原原生类型（json_extract 数字键保持数字）
     */
    private function decodeKey(mixed $key): mixed
    {
        if (is_string($key) && ($key === '' || str_starts_with($key, '{') || str_starts_with($key, '['))) {
            $decoded = json_decode($key, true);
            return $decoded === null && $key !== 'null' ? $key : $decoded;
        }
        return $key;
    }

    // ------------------------------------------------------------------
    // 基础设施
    // ------------------------------------------------------------------

    /**
     * 文档 → 行：专列拆分 + 其余字段 JSON
     *
     * @return array<string, mixed>
     */
    private function toRow(string $indexName, array $doc): array
    {
        $ts = $doc['ts'] ?? null;
        $tsMs = $this->compiler->toMs($ts);
        if ($tsMs === null) {
            $tsMs = (int) (microtime(true) * 1000);
            $ts = $ts instanceof \Stringable || is_scalar($ts) ? (string) $ts : now()->toISOString();
        }

        $fields = [];
        foreach ($doc as $k => $v) {
            if (in_array($k, ['msg', 'level', 'ts', 'workspace_id', 'topic_id', 'tenant_id'], true)) {
                continue;
            }
            $fields[$k] = $v;
        }

        return [
            'ts'           => is_scalar($ts) || $ts === null ? $ts : (string) $ts,
            'ts_ms'        => $tsMs,
            'level'        => isset($doc['level']) && is_scalar($doc['level']) ? (string) $doc['level'] : null,
            'msg'          => isset($doc['msg']) && is_scalar($doc['msg']) ? (string) $doc['msg'] : null,
            'workspace_id' => isset($doc['workspace_id']) && is_scalar($doc['workspace_id']) ? (string) $doc['workspace_id'] : null,
            'topic_id'     => isset($doc['topic_id']) && is_scalar($doc['topic_id']) ? (string) $doc['topic_id'] : null,
            'tenant_id'    => isset($doc['tenant_id']) && is_scalar($doc['tenant_id']) ? (string) $doc['tenant_id'] : null,
            'fields'       => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'doc'          => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 索引模式（easylog-{ws}-*）→ index_name LIKE 条件
     *
     * @param array<int,mixed> $bindings
     */
    private function indexCondition(string $indexPattern, array &$bindings): string
    {
        if (str_contains($indexPattern, '*') || str_contains($indexPattern, '?')) {
            $escaped = str_replace(['\\', '%', '_', '*', '?'], ['\\\\', '\\%', '\\_', '%', '_'], $indexPattern);
            $bindings[] = $escaped;
            $b = count($bindings);
            return "index_name LIKE ?{$b} ESCAPE '\\'";
        }
        $bindings[] = $indexPattern;
        $b = count($bindings);
        return "index_name = ?{$b}";
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $path = (string) ($this->config['path'] ?? database_path('easylog-logs.sqlite'));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $this->pdo = $pdo;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS easylog_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                index_name TEXT NOT NULL,
                ts TEXT,
                ts_ms INTEGER NOT NULL,
                level TEXT,
                msg TEXT,
                workspace_id TEXT,
                topic_id TEXT,
                tenant_id TEXT,
                fields TEXT,
                doc TEXT NOT NULL
            )'
        );
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_logs_index_ts ON easylog_logs (index_name, ts_ms)');
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_logs_ws_topic ON easylog_logs (workspace_id, topic_id)');
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_logs_level ON easylog_logs (level)');
        $this->schemaReady = true;
    }

    private bool $schemaReady = false;
}
