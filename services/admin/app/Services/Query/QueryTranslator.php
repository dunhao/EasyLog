<?php

declare(strict_types=1);

namespace EasyLog\Services\Query;

use RuntimeException;

/**
 * 查询字符串 → Elasticsearch Query DSL 转译器
 *
 * MVP 支持的简化 Lucene 语法：
 *
 *   - 关键词：                    foo bar        → match msg
 *   - 字段值：                    level:ERROR     → term level=ERROR
 *   - 字段值带空格：              msg:"order created" → match msg.phrase
 *   - 通配符：                    service:order-* → wildcard
 *   - 布尔操作：                  AND  OR  NOT    → bool/must/should/must_not
 *   - 范围（仅 level 等关键字段）： level:[ERROR TO FATAL]
 *   - 小括号分组：                (level:ERROR OR level:FATAL) AND service:order
 *
 * 空查询：match_all
 *
 * 不在 MVP 范围：正则、模糊匹配 ~、加权 ^、ES SQL。
 */
class QueryTranslator
{
    /**
     * 转译主入口
     *
     * @param string $query 用户输入的查询字符串
     * @param array{from?:string,to?:string,size?:int,sort?:array,fields?:array} $context
     * @return array<string,mixed> 完整的 ES DSL（含 query/from/size/sort/_source）
     */
    public function translate(string $query, array $context = []): array
    {
        $query = trim($query);

        $dsl = [
            'query' => [
                'bool' => [
                    'must'   => $this->buildMust($query),
                    'filter' => $this->buildTimeFilter($context),
                ],
            ],
            'from' => max(0, (int) ($context['from_offset'] ?? 0)),
            'size' => min(1000, max(1, (int) ($context['size'] ?? 100))),
        ];

        if (!empty($context['sort'])) {
            $dsl['sort'] = $this->buildSort($context['sort']);
        } else {
            // 默认按时间倒序
            $dsl['sort'] = [['ts' => ['order' => 'desc']]];
        }

        if (!empty($context['fields'])) {
            $dsl['_source'] = $context['fields'];
        }

        return $dsl;
    }

    /**
     * 直方图查询 DSL
     *
     * @param array{from?:string,to?:string,bucket?:string,query?:string} $context
     */
    public function histogram(string $query, array $context): array
    {
        $interval = $context['bucket'] ?? 'minute';
        $calendar = match ($interval) {
            'minute' => '1m',
            'hour'   => '1h',
            'day'    => '1d',
            default  => '1m',
        };

        return [
            'query' => [
                'bool' => [
                    'must'   => $this->buildMust(trim($query)),
                    'filter' => $this->buildTimeFilter($context),
                ],
            ],
            'size'  => 0,
            'aggs'  => [
                'histogram' => [
                    'date_histogram' => [
                        'field'    => 'ts',
                        'calendar_interval' => $calendar,
                        'min_doc_count' => 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * 聚合查询 DSL
     *
     * @param array{agg_type:string,field?:string,size?:int,bucket?:string,query?:string,from?:string,to?:string} $context
     */
    public function aggregate(string $query, array $context): array
    {
        $aggType = $context['agg_type'] ?? 'terms';
        $field   = $context['field'] ?? 'level';
        $size    = (int) ($context['size'] ?? 10);

        $aggDef = match ($aggType) {
            'terms'         => ['terms' => ['field' => $field, 'size' => $size]],
            'date_histogram'=> ['date_histogram' => [
                'field' => $field,
                'calendar_interval' => $context['bucket'] ?? '1h',
                'min_doc_count' => 0,
            ]],
            'avg'           => ['avg'   => ['field' => $field]],
            'sum'           => ['sum'   => ['field' => $field]],
            'max'           => ['max'   => ['field' => $field]],
            'min'           => ['min'   => ['field' => $field]],
            'cardinality'   => ['cardinality' => ['field' => $field]],
            default         => ['terms' => ['field' => $field, 'size' => $size]],
        };

        return [
            'query' => [
                'bool' => [
                    'must'   => $this->buildMust(trim($query)),
                    'filter' => $this->buildTimeFilter($context),
                ],
            ],
            'size' => 0,
            'aggs' => ['result' => $aggDef],
        ];
    }

    /**
     * 解析查询字符串为 ES must 子句数组
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildMust(string $query): array
    {
        // 空查询与 * / *:* 都视为 match_all。
        // 注意：'*' 必须显式处理——否则会落到下面的关键字分支生成
        // match: {msg: '*'}，而 ES 的 match 查询会把 '*' 当普通词分析掉，
        // 结果是一个字都匹配不到（静默返回 0 条），非常容易踩坑。
        if ($query === '' || $query === '*' || $query === '*:*') {
            return [['match_all' => new \stdClass()]];
        }

        // 先按 OR 切分（顶层）
        $orParts = $this->splitTopLevel($query, [' OR ', ' | ']);
        if (count($orParts) > 1) {
            $should = [];
            foreach ($orParts as $part) {
                $must = $this->buildMust(trim($part));
                if (!empty($must)) {
                    $should[] = ['bool' => ['must' => $must]];
                }
            }
            return [['bool' => ['should' => $should, 'minimum_should_match' => 1]]];
        }

        // 按 AND 切分（顶层）
        $andParts = $this->splitTopLevel($query, [' AND ', ' & ']);
        $must = [];
        foreach ($andParts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $clause = $this->parseClause($part);
            if ($clause !== null) {
                $must[] = $clause;
            }
        }
        return $must;
    }

    /**
     * 解析单条表达式（字段:值 / 关键字 / NOT 表达式 / 括号分组）
     */
    private function parseClause(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') return null;

        // NOT 前缀
        if (preg_match('/^NOT\s+(.+)$/i', $expr, $m)) {
            $inner = $this->parseClause($m[1]);
            return $inner ? ['bool' => ['must_not' => [$inner]]] : null;
        }

        // 括号分组
        if (str_starts_with($expr, '(') && str_ends_with($expr, ')')) {
            $inner = trim(substr($expr, 1, -1));
            $must = $this->buildMust($inner);
            return $must ? ['bool' => ['must' => $must]] : null;
        }

        // field:[A TO B] 范围
        if (preg_match('/^(\w+):\[(.+?)\s+TO\s+(.+?)\]$/i', $expr, $m)) {
            return ['range' => [$m[1] => ['gte' => trim($m[2]), 'lte' => trim($m[3])]]];
        }

        // field:value
        if (preg_match('/^(\w+):(.+)$/', $expr, $m)) {
            $field = $m[1];
            $value = trim($m[2], ' "');
            if ($value === '') return null;

            // 通配符
            if (str_contains($value, '*') || str_contains($value, '?')) {
                return ['wildcard' => [$field => ['value' => $value]]];
            }

            // 短语（带空格的引号值）
            if (str_contains($value, ' ')) {
                return ['match' => [$field => ['query' => $value, 'operator' => 'and']]];
            }

            // keyword 字段走 term
            return ['term' => [$field => $value]];
        }

        // 单纯关键字：丢到 msg 全文
        $value = trim($expr, '"');
        return ['match' => ['msg' => $value]];
    }

    /**
     * 时间范围 filter
     *
     * @param array{from?:string,to?:string} $context
     */
    private function buildTimeFilter(array $context): array
    {
        $range = [];
        if (!empty($context['from'])) {
            $range['gte'] = $context['from'];
        }
        if (!empty($context['to'])) {
            $range['lte'] = $context['to'];
        }
        if (empty($range)) return [];
        return [['range' => ['ts' => $range]]];
    }

    /**
     * 排序子句构造
     */
    private function buildSort(array $sort): array
    {
        $out = [];
        foreach ($sort as $k => $v) {
            if (is_int($k)) {
                // ['ts:desc', 'level:asc'] 形式
                if (is_string($v) && str_contains($v, ':')) {
                    [$f, $o] = explode(':', $v, 2);
                    $out[] = [$f => ['order' => $o]];
                } else {
                    $out[] = [$v => ['order' => 'desc']];
                }
            } elseif (is_array($v)) {
                // ['ts' => 'desc'] 形式
                $out[] = [$k => ['order' => $v[0] ?? 'desc']];
            } else {
                $out[] = [$k => ['order' => $v]];
            }
        }
        return $out;
    }

    /**
     * 顶层按操作符切分（保留括号内不被切）
     *
     * @param string[] $separators
     * @return string[]
     */
    private function splitTopLevel(string $query, array $separators): array
    {
        // 简化实现：把括号内的内容临时替换为占位符，再按 separator 切，再换回
        $placeholders = [];
        $masked = preg_replace_callback('/\([^)]*\)/', function ($m) use (&$placeholders) {
            $key = "\x01PH" . count($placeholders) . "\x01";
            $placeholders[$key] = $m[0];
            return $key;
        }, $query) ?? $query;

        $result = [$masked];
        foreach ($separators as $sep) {
            $tmp = [];
            foreach ($result as $chunk) {
                $tmp = array_merge($tmp, explode($sep, $chunk));
            }
            $result = $tmp;
        }

        // 还原占位符
        $final = [];
        foreach ($result as $chunk) {
            $final[] = strtr($chunk, $placeholders);
        }
        return $final;
    }
}
