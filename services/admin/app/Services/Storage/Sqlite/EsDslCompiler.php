<?php

declare(strict_types=1);

namespace EasyLog\Services\Storage\Sqlite;

/**
 * ES Query DSL（Lite 子集）→ SQLite WHERE/ORDER BY 编译器
 *
 * 覆盖 QueryTranslator / SqlQueryTranslator 会产出的全部子句：
 * - bool: must / filter / should(minimum_should_match=1) / must_not，可嵌套
 * - match_all / term / terms / match(query+operator) / match_phrase
 * - wildcard(* ?) / range(gte gt lte lt) / exists
 *
 * 字段寻址：ts/level/msg/workspace_id/topic_id 走专列，其余业务字段走
 * json_extract(fields)；字段名白名单校验防注入，非法字段名编译为恒假。
 */
class EsDslCompiler
{
    /** 专列字段（与 easylog_logs 表列一一对应） */
    private const COLUMNS = [
        'msg'          => 'msg',
        'level'        => 'level',
        'workspace_id' => 'workspace_id',
        'topic_id'     => 'topic_id',
        'tenant_id'    => 'tenant_id',
    ];

    /** 字段名白名单：字母数字下划线（对齐 QueryTranslator 的 \w+ 约束） */
    private const FIELD_PATTERN = '/^\w{1,64}$/';

    /**
     * 编译 DSL 的 query 部分为 WHERE 片段
     *
     * @param array<string,mixed> $query DSL ['query'] 节点（或裸 ['bool'=>...] 节点）
     * @return array{where:string, bindings:array<int,mixed>}
     */
    public function compileWhere(array $query): array
    {
        $bindings = [];
        $bool = $query['bool'] ?? ($query['query']['bool'] ?? []);
        $where = $this->compileBool($bool, $bindings);
        return ['where' => $where, 'bindings' => $bindings];
    }

    /**
     * 编译 bool 节点（must/filter/should/must_not），绑定参数引用透传保证序号连续
     *
     * @param array<string,mixed> $bool
     * @param array<int,mixed> $bindings
     */
    private function compileBool(array $bool, array &$bindings): string
    {
        $clauses = [];

        foreach (['must', 'filter'] as $section) {
            foreach ($this->clauseList($bool[$section] ?? null) as $clause) {
                $clauses[] = $this->compileClause($clause, $bindings);
            }
        }
        if (!empty($bool['should'])) {
            $parts = [];
            foreach ($this->clauseList($bool['should']) as $clause) {
                $parts[] = $this->compileClause($clause, $bindings);
            }
            if ($parts !== []) {
                $clauses[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        foreach ($this->clauseList($bool['must_not'] ?? null) as $clause) {
            $clauses[] = 'NOT (' . $this->compileClause($clause, $bindings) . ')';
        }

        if ($clauses === []) {
            return '1=1';
        }

        return implode(' AND ', $clauses);
    }

    /**
     * 编译 sort 子句：[[field => ['order' => asc|desc]], ...]
     *
     * @param array<int,array<string,mixed>> $sort
     * @return string ORDER BY 片段（无合法字段时按 ts_ms DESC）
     */
    public function compileSort(array $sort): string
    {
        $parts = [];
        foreach ($sort as $item) {
            foreach ($item as $field => $spec) {
                $order = strtolower(is_array($spec) ? (string) ($spec['order'] ?? 'desc') : (string) $spec);
                if (!in_array($order, ['asc', 'desc'], true)) {
                    $order = 'desc';
                }
                if ($field === 'ts') {
                    $parts[] = "ts_ms {$order}";
                    continue;
                }
                if (!preg_match(self::FIELD_PATTERN, (string) $field)) {
                    continue;
                }
                $expr = $this->fieldExpr((string) $field);
                if ($expr === null) {
                    continue;
                }
                $parts[] = "{$expr} {$order}";
            }
        }
        if ($parts === []) {
            return 'ts_ms DESC';
        }
        return implode(', ', $parts);
    }

    /**
     * 从 DSL filter 中提取 ts 范围（直方图空桶填充用）
     *
     * @param array<string,mixed> $query DSL ['query'] 节点
     * @return array{from_ms:?int, to_ms:?int}
     */
    public function extractTimeRange(array $query): array
    {
        $from = null;
        $to = null;
        $walk = function (array $node) use (&$walk, &$from, &$to): void {
            $bool = $node['bool'] ?? $node;
            foreach (['must', 'filter', 'should', 'must_not'] as $section) {
                foreach ($this->clauseList($bool[$section] ?? null) as $clause) {
                    $range = $clause['range']['ts'] ?? null;
                    if (is_array($range)) {
                        $from ??= isset($range['gte']) ? $this->toMs($range['gte']) : (isset($range['gt']) ? $this->toMs($range['gt']) : null);
                        $to   ??= isset($range['lte']) ? $this->toMs($range['lte']) : (isset($range['lt']) ? $this->toMs($range['lt']) : null);
                    }
                    if (isset($clause['bool']) && is_array($clause['bool'])) {
                        $walk($clause['bool']);
                    }
                }
            }
        };
        $walk($query);
        return ['from_ms' => $from, 'to_ms' => $to];
    }

    /**
     * 字段访问表达式：专列 or json_extract；非法字段返回 null
     */
    public function fieldExpr(string $field): ?string
    {
        if (isset(self::COLUMNS[$field])) {
            return self::COLUMNS[$field];
        }
        if (!preg_match(self::FIELD_PATTERN, $field)) {
            return null;
        }
        return "json_extract(fields, '\$.\"{$field}\"')";
    }

    /**
     * 编译单个查询子句
     *
     * @param array<string,mixed> $clause
     * @param array<int,mixed> $bindings 引用收集绑定参数
     */
    private function compileClause(array $clause, array &$bindings): string
    {
        // 嵌套 bool：绑定参数经引用透传，保证序号跨层级连续
        if (isset($clause['bool']) && is_array($clause['bool'])) {
            return '(' . $this->compileBool($clause['bool'], $bindings) . ')';
        }
        if (isset($clause['match_all'])) {
            return '1=1';
        }

        foreach (['term', 'terms', 'match', 'match_phrase', 'wildcard', 'range', 'exists'] as $type) {
            if (!isset($clause[$type]) || !is_array($clause[$type])) {
                continue;
            }
            $spec = $clause[$type];
            $field = (string) key($spec);
            $body = $spec[$field];
            return match ($type) {
                'term'        => $this->compileTerm($field, $body, $bindings),
                'terms'       => $this->compileTerms($field, $body, $bindings),
                'match'       => $this->compileMatch($field, $body, $bindings),
                'match_phrase'=> $this->compilePhrase($field, $body, $bindings),
                'wildcard'    => $this->compileWildcard($field, $body, $bindings),
                'range'       => $this->compileRange($field, is_array($body) ? $body : [], $bindings),
                'exists'      => $this->compileExists($field),
                default       => '1=0',
            };
        }

        return '1=0';
    }

    /**
     * term：等值匹配。数字/字符串统一按"数值等 or 文本等"双路径，
     * 兼容业务字段在 JSON 中以数字或字符串两种形态存储
     */
    private function compileTerm(string $field, mixed $value, array &$bindings): string
    {
        $expr = $this->fieldExpr($field);
        if ($expr === null) {
            return '1=0';
        }
        $bindings[] = $value;
        $b1 = count($bindings);
        $bindings[] = (string) $value;
        $b2 = count($bindings);

        return "({$expr} = ?{$b1} OR CAST({$expr} AS TEXT) = ?{$b2})";
    }

    /**
     * @param array<int,mixed> $values
     */
    private function compileTerms(string $field, array $values, array &$bindings): string
    {
        $expr = $this->fieldExpr($field);
        if ($expr === null || $values === []) {
            return '1=0';
        }
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->compileTerm($field, $value, $bindings);
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * match：全文/字段包含。
     * - 值含空格且 operator=and → 短语包含（整串）
     * - 值含空格无 operator → 分词后任一命中（对齐 ES 默认 OR）
     * - 单词 → 子串包含
     */
    private function compileMatch(string $field, mixed $body, array &$bindings): string
    {
        if (is_array($body)) {
            $value = (string) ($body['query'] ?? '');
            $operator = strtolower((string) ($body['operator'] ?? 'or'));
        } else {
            $value = (string) $body;
            $operator = 'or';
        }
        if ($value === '') {
            return '1=0';
        }
        $field = $field === '' ? 'msg' : $field;
        $expr = $this->fieldExpr($field);
        if ($expr === null) {
            return '1=0';
        }

        if (str_contains($value, ' ') && $operator === 'and') {
            return $this->likeCondition($expr, $value, $bindings);
        }

        $tokens = preg_split('/\s+/', $value) ?: [$value];
        $parts = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $parts[] = $this->likeCondition($expr, $token, $bindings);
        }
        if ($parts === []) {
            return '1=0';
        }
        return $operator === 'and'
            ? '(' . implode(' AND ', $parts) . ')'
            : '(' . implode(' OR ', $parts) . ')';
    }

    private function compilePhrase(string $field, mixed $body, array &$bindings): string
    {
        $value = is_array($body) ? (string) ($body['query'] ?? '') : (string) $body;
        $field = $field === '' ? 'msg' : $field;
        $expr = $this->fieldExpr($field);
        if ($expr === null || $value === '') {
            return '1=0';
        }
        return $this->likeCondition($expr, $value, $bindings);
    }

    /**
     * wildcard：ES 通配符 * ? → SQL LIKE % _（其余字符转义）
     */
    private function compileWildcard(string $field, mixed $body, array &$bindings): string
    {
        $value = is_array($body) ? (string) ($body['value'] ?? '') : (string) $body;
        $expr = $this->fieldExpr($field);
        if ($expr === null || $value === '') {
            return '1=0';
        }
        $escaped = str_replace(['\\', '%', '_', '*'], ['\\\\', '\\%', '\\_', '%'], $value);
        $escaped = str_replace('?', '_', $escaped);
        $bindings[] = '%' . $escaped . '%';
        $b = count($bindings);

        return "CAST({$expr} AS TEXT) LIKE ?{$b} ESCAPE '\\'";
    }

    /**
     * range：ts 走 ts_ms 毫秒比较；level 等文本字段走文本比较；其余按数值比较
     *
     * @param array<string,mixed> $body
     */
    private function compileRange(string $field, array $body, array &$bindings): string
    {
        if ($field === 'ts') {
            $expr = 'ts_ms';
            $convert = fn ($v) => $this->toMs($v);
        } else {
            $expr = $this->fieldExpr($field);
            if ($expr === null) {
                return '1=0';
            }
            if ($field === 'level') {
                $convert = fn ($v) => is_scalar($v) ? (string) $v : json_encode($v);
            } else {
                $numeric = "CAST({$expr} AS REAL)";
                $parts = [];
                foreach (['gte', 'gt', 'lte', 'lt'] as $op) {
                    if (!array_key_exists($op, $body)) {
                        continue;
                    }
                    $bindings[] = is_numeric($body[$op]) ? $body[$op] + 0 : (float) $body[$op];
                    $b = count($bindings);
                    $sqlOp = match ($op) {
                        'gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<',
                    };
                    $parts[] = "{$numeric} {$sqlOp} ?{$b}";
                }
                return $parts === [] ? '1=1' : '(' . implode(' AND ', $parts) . ')';
            }
        }

        $parts = [];
        foreach (['gte', 'gt', 'lte', 'lt'] as $op) {
            if (!array_key_exists($op, $body)) {
                continue;
            }
            $converted = $convert($body[$op]);
            if ($converted === null) {
                continue;
            }
            $bindings[] = $converted;
            $b = count($bindings);
            $sqlOp = match ($op) {
                'gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<',
            };
            $parts[] = "{$expr} {$sqlOp} ?{$b}";
        }
        return $parts === [] ? '1=1' : '(' . implode(' AND ', $parts) . ')';
    }

    private function compileExists(string $field): string
    {
        $expr = $this->fieldExpr($field);
        if ($expr === null) {
            return '1=0';
        }
        return "{$expr} IS NOT NULL AND {$expr} IS NOT ''";
    }

    /**
     * 子串包含条件（LIKE + ESCAPE，值参数化）
     */
    private function likeCondition(string $expr, string $value, array &$bindings): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $bindings[] = '%' . $escaped . '%';
        $b = count($bindings);

        return "CAST({$expr} AS TEXT) LIKE ?{$b} ESCAPE '\\'";
    }

    /**
     * 时间值 → 毫秒时间戳；解析失败返回 null
     */
    public function toMs(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $num = (int) $value;
            // 秒级时间戳（10 位）归一为毫秒
            return $num < 10_000_000_000 ? $num * 1000 : $num;
        }
        try {
            return \Carbon\Carbon::parse((string) $value)->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $node
     * @return array<int, array<string,mixed>>
     */
    private function clauseList(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $list = [];
        foreach ($node as $item) {
            if (is_array($item) && $item !== []) {
                $list[] = $item;
            }
        }
        return $list;
    }
}
