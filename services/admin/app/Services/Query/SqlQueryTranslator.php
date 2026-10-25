<?php

declare(strict_types=1);

namespace EasyLog\Services\Query;

/**
 * 简化 SQL → 查询 DSL 转译器（v1.5）
 *
 * 支持的语法子集：
 *
 *   SELECT * | field[, field...] FROM <source>
 *   [WHERE cond [AND|OR ...]]
 *   [GROUP BY field]
 *   [ORDER BY field [ASC|DESC][, ...]]
 *   [LIMIT n]
 *
 * WHERE 条件：
 *   field = 'v'          → term / 短语 match
 *   field != 'v'         → must_not term
 *   field > >= < <= v    → range
 *   field LIKE 'a%b_'    → wildcard（% → *，_ → ?）
 *   field IN ('a','b')   → terms
 *   field BETWEEN a AND b → range
 *   field IS [NOT] NULL  → exists
 *   AND / OR / NOT / (…) 组合
 *
 * SELECT 列表含聚合函数（count/avg/sum/min/max/cardinality）或 GROUP BY 时
 * 产出聚合 DSL（aggregate=true）；否则为普通检索 DSL。
 *
 * 不在范围：JOIN、HAVING、子查询、多表。
 */
class SqlQueryTranslator
{
    // ---------- 对外入口 ----------

    public static function looksLikeSql(string $query): bool
    {
        return preg_match('/^\s*select\b/i', $query) === 1;
    }

    /**
     * SQL → 检索/聚合 DSL
     *
     * @param array{from?:string,to?:string,size?:int,sort?:array,fields?:array} $context
     * @return array{dsl:array<string,mixed>, aggregate:bool}
     * @throws SqlParseException
     */
    public function translate(string $sql, array $context = []): array
    {
        $parsed = $this->parse($sql);

        $must = $parsed['where'];
        $filter = $this->timeFilter($context);

        $dsl = [
            'query' => ['bool' => array_filter([
                'must'   => $must,
                'filter' => $filter,
            ])],
            'from' => max(0, (int) ($context['from_offset'] ?? 0)),
            'size' => min(1000, max(1, (int) ($parsed['limit'] ?? $context['size'] ?? 100))),
        ];

        // ORDER BY：SQL 指定优先，缺省 ts 倒序（与 QueryTranslator 行为一致）
        if ($parsed['order']) {
            $dsl['sort'] = $parsed['order'];
        } elseif (empty($context['sort'])) {
            $dsl['sort'] = [['ts' => ['order' => 'desc']]];
        } else {
            $out = [];
            foreach ($context['sort'] as $k => $v) {
                $out[] = is_int($k) ? [$v => ['order' => 'desc']] : [$k => ['order' => $v]];
            }
            $dsl['sort'] = $out;
        }

        if ($parsed['source'] !== ['*']) {
            $dsl['_source'] = $parsed['source'];
        }

        if ($parsed['aggregate']) {
            $dsl['size'] = 0;
            $dsl['aggs'] = ['result' => $parsed['agg_def']];
            return ['dsl' => $dsl, 'aggregate' => true];
        }

        return ['dsl' => $dsl, 'aggregate' => false];
    }

    /**
     * 仅解析 WHERE 子句为 must 数组（供 histogram / aggregate 接口做 SQL 过滤）
     *
     * @return array<int,array<string,mixed>>
     * @throws SqlParseException
     */
    public function mustClauses(string $sql): array
    {
        return $this->parse($sql)['where'];
    }

    // ---------- 解析器 ----------

    /**
     * @return array{source:string[], where:array, order:array, limit:?int,
     *               aggregate:bool, agg_def:array}
     */
    private function parse(string $sql): array
    {
        $tokens = (new SqlTokenizer($sql))->tokenize();
        $p = new SqlTokenCursor($tokens);

        $p->expectKeyword('SELECT');
        $selectList = $this->parseSelectList($p);

        $p->expectKeyword('FROM');
        $p->expectIdent(); // 表名忽略（由路由决定索引）

        $where = [];
        if ($p->peekKeyword('WHERE')) {
            $p->next();
            $where = $this->parseExpr($p);
        }

        $groupField = null;
        if ($p->peekKeyword('GROUP')) {
            $p->next();
            $p->expectKeyword('BY');
            $groupField = $p->expectIdent();
        }

        $order = [];
        if ($p->peekKeyword('ORDER')) {
            $p->next();
            $p->expectKeyword('BY');
            do {
                $field = $p->expectIdent();
                $dir = 'desc';
                if ($p->peekKeyword('ASC')) { $p->next(); $dir = 'asc'; }
                elseif ($p->peekKeyword('DESC')) { $p->next(); $dir = 'desc'; }
                $order[] = [$field => ['order' => $dir]];
                $hasComma = $p->peekOp(',');
                if ($hasComma) $p->next();
            } while ($hasComma);
        }

        $limit = null;
        if ($p->peekKeyword('LIMIT')) {
            $p->next();
            $limit = (int) $p->expectNumber();
        }

        if (!$p->eof()) {
            $extra = $p->peek();
            throw new SqlParseException("意外的标记 '{$extra['value']}'");
        }

        return $this->buildResult($selectList, $where, $order, $limit, $groupField);
    }

    /**
     * @return array{fields:string[], aggregates:array<string,array>, hasAggregate:bool}
     */
    private function parseSelectList(SqlTokenCursor $p): array
    {
        $fields = [];
        $aggregates = [];
        $hasAggregate = false;

        if ($p->peekOp('*')) {
            $p->next();
            return ['fields' => ['*'], 'aggregates' => [], 'hasAggregate' => false];
        }

        do {
            if ($p->peekOp(',')) { $p->next(); }
            if ($p->eof()) break;

            $fn = null;
            $ident = $p->expectIdent();
            if ($p->peekOp('(')) {
                $p->next();
                $fn = strtolower($ident);
                if (!in_array($fn, ['count', 'avg', 'sum', 'min', 'max', 'cardinality'], true)) {
                    throw new SqlParseException("不支持的聚合函数：{$ident}");
                }
                $distinct = false;
                if ($p->peekKeyword('DISTINCT')) { $p->next(); $distinct = true; }
                if ($p->peekOp('*')) {
                    $p->next();
                    $field = '*';
                    if ($fn !== 'count') throw new SqlParseException("{$fn}(*) 不合法");
                } else {
                    $field = $p->expectIdent();
                }
                $p->expectOp(')');
                $hasAggregate = true;
                $aggregates[] = ['fn' => $fn, 'field' => $field, 'distinct' => $distinct];
            } else {
                $fields[] = $ident;
            }
        } while ($p->peekOp(','));

        return ['fields' => $fields ?: ['*'], 'aggregates' => $aggregates, 'hasAggregate' => $hasAggregate];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseExpr(SqlTokenCursor $p): array
    {
        return $this->parseOr($p);
    }

    private function parseOr(SqlTokenCursor $p): array
    {
        $operands = [$this->parseAnd($p)];
        while ($p->peekKeyword('OR')) {
            $p->next();
            $operands[] = $this->parseAnd($p);
        }
        if (count($operands) === 1) {
            return $operands[0];
        }
        // 与 QueryTranslator 的 OR 形状一致：每个操作数的子句列表包在 bool/must 里
        $should = array_map(fn (array $list) => ['bool' => ['must' => $list]], $operands);
        return [['bool' => ['should' => $should, 'minimum_should_match' => 1]]];
    }

    private function parseAnd(SqlTokenCursor $p): array
    {
        $clauses = $this->parseNot($p);
        while ($p->peekKeyword('AND')) {
            $p->next();
            $clauses = array_merge($clauses, $this->parseNot($p));
        }
        return $clauses;
    }

    private function parseNot(SqlTokenCursor $p): array
    {
        if ($p->peekKeyword('NOT')) {
            $p->next();
            // NOT(a AND b) ≠ must_not[a, b]（后者是 NOT(a OR b)），
            // 先把内层子句列表包成 bool/must 再整体否定
            $inner = $this->parseNot($p);
            return [['bool' => ['must_not' => [['bool' => ['must' => $inner]]]]]];
        }
        return $this->parsePrimary($p);
    }

    private function parsePrimary(SqlTokenCursor $p): array
    {
        if ($p->peekOp('(')) {
            $p->next();
            $inner = $this->parseOr($p);
            $p->expectOp(')');
            return $inner;
        }
        return [$this->parseComparison($p)];
    }

    private function parseComparison(SqlTokenCursor $p): array
    {
        $field = $p->expectIdent();

        if ($p->peekKeyword('IS')) {
            $p->next();
            $not = $p->peekKeyword('NOT');
            if ($not) $p->next();
            $p->expectKeyword('NULL');
            // 归一化：IS NULL → must_not(exists)；IS NOT NULL → exists
            return $not
                ? ['exists' => ['field' => $field]]
                : ['bool' => ['must_not' => [['exists' => ['field' => $field]]]]];
        }

        if ($p->peekKeyword('LIKE')) {
            $p->next();
            $pattern = $p->expectString();
            $wildcard = str_replace(['%', '_'], ['*', '?'], $pattern);
            if (!str_contains($wildcard, '*') && !str_contains($wildcard, '?')) {
                $wildcard .= '*'; // LIKE 默认前缀隐含 %
            }
            return ['wildcard' => [$field => ['value' => $wildcard]]];
        }

        if ($p->peekKeyword('IN')) {
            $p->next();
            $p->expectOp('(');
            $values = [$p->expectValue()];
            while ($p->peekOp(',')) {
                $p->next();
                $values[] = $p->expectValue();
            }
            $p->expectOp(')');
            return ['terms' => [$field => $values]];
        }

        if ($p->peekKeyword('BETWEEN')) {
            $p->next();
            $low = $p->expectValue();
            $p->expectKeyword('AND');
            $high = $p->expectValue();
            return ['range' => [$field => ['gte' => $low, 'lte' => $high]]];
        }

        $op = $p->expectOp(['=', '!=', '<>', '>', '>=', '<', '<=']);
        $value = $p->expectValue();

        return match ($op) {
            '=', '==' => str_contains($value, ' ')
                ? ['match' => [$field => ['query' => $value, 'operator' => 'and']]]
                : ['term' => [$field => $value]],
            '!=', '<>' => ['bool' => ['must_not' => [['term' => [$field => $value]]]]],
            '>', '>=', '<', '<=' => ['range' => [$field => [match ($op) {
                '>' => 'gt', '>=' => 'gte', '<' => 'lt', default => 'lte',
            } => $value]]],
            default => throw new SqlParseException("不支持的操作符 {$op}"),
        };
    }

    /**
     * @param array{fields:string[], aggregates:array, hasAggregate:bool} $select
     * @return array{source:string[], where:array, order:array, limit:?int, aggregate:bool, agg_def:array}
     */
    private function buildResult(array $select, array $where, array $order, ?int $limit, ?string $groupField): array
    {
        $aggregate = $select['hasAggregate'] || $groupField !== null;
        $aggDef = [];

        if ($aggregate) {
            if ($groupField !== null) {
                // GROUP BY：terms 聚合；SELECT 中的其它聚合函数作为子聚合暂不支持（v1.5 只取分组计数/单指标）
                if (count($select['aggregates']) === 1 && $select['aggregates'][0]['fn'] !== 'count') {
                    $agg = $select['aggregates'][0];
                    $aggDef = ['terms' => ['field' => $groupField, 'size' => 10]];
                    $subFn = $agg['fn'] === 'cardinality' ? 'cardinality' : $agg['fn'];
                    $aggDef['aggs'] = ['metric' => [$subFn => ['field' => $agg['field']]]];
                } else {
                    $aggDef = ['terms' => ['field' => $groupField, 'size' => 10]];
                }
            } else {
                $agg = $select['aggregates'][0] ?? ['fn' => 'count', 'field' => '*', 'distinct' => false];
                $aggDef = match ($agg['fn']) {
                    // count(*) → 对恒存的数值时间字段计数；count(field) → 字段计数
                    'count' => $agg['distinct']
                        ? ['cardinality' => ['field' => $agg['field']]]
                        : ($agg['field'] === '*' ? ['value_count' => ['field' => '_ts']] : ['value_count' => ['field' => $agg['field']]]),
                    'avg', 'sum', 'min', 'max' => [$agg['fn'] => ['field' => $agg['field']]],
                    'cardinality'  => ['cardinality' => ['field' => $agg['field']]],
                    default        => ['value_count' => ['field' => '_ts']],
                };
            }
        }

        return [
            'source'    => $select['fields'],
            'where'     => $where,
            'order'     => $order,
            'limit'     => $limit,
            'aggregate' => $aggregate,
            'agg_def'   => $aggDef,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function timeFilter(array $context): array
    {
        $range = [];
        if (!empty($context['from'])) $range['gte'] = $context['from'];
        if (!empty($context['to'])) $range['lte'] = $context['to'];
        if (empty($range)) return [];
        return [['range' => ['ts' => $range]]];
    }
}
