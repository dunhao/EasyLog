<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use EasyLog\Services\Storage\OpenObserveAdapter;
use EasyLog\Services\Storage\QuickwitAdapter;
use PHPUnit\Framework\TestCase;

/**
 * 存储适配器 DSL 反向转译测试（纯函数，不发 HTTP）
 */
class StorageDslTranslationTest extends TestCase
{
    // ---------- OpenObserve：DSL → SQL WHERE ----------

    public function testOoTermClause(): void
    {
        $sql = OpenObserveAdapter::buildWhere(['term' => ['level' => 'ERROR']]);
        $this->assertSame("\"level\" = 'ERROR'", $sql);
    }

    public function testOoMatchBecomesLike(): void
    {
        $sql = OpenObserveAdapter::buildWhere(['match' => ['msg' => ['query' => 'timeout', 'operator' => 'and']]]);
        $this->assertSame("\"msg\" LIKE '%timeout%'", $sql);
    }

    public function testOoWildcardBecomesLike(): void
    {
        $sql = OpenObserveAdapter::buildWhere(['wildcard' => ['service' => ['value' => 'order-*']]]);
        $this->assertSame("\"service\" LIKE 'order-%'", $sql);
    }

    public function testOoTsRangeBecomesTimestampMicros(): void
    {
        $sql = OpenObserveAdapter::buildWhere(['range' => ['ts' => ['gte' => '2026-09-25T00:00:00Z', 'lte' => '2026-09-25T01:00:00Z']]]);
        $this->assertStringContainsString('_timestamp >= ', $sql);
        $this->assertStringContainsString('_timestamp <= ', $sql);
        // 2026-09-25T00:00:00Z = 1790294400 秒 = 1790294400000000 微秒
        $this->assertStringContainsString('1790294400000000', $sql);
    }

    public function testOoBoolWithShouldAndMustNot(): void
    {
        $sql = OpenObserveAdapter::buildWhere(['bool' => [
            'must' => [['term' => ['level' => 'ERROR']]],
            'should' => [['term' => ['service' => 'a']], ['term' => ['service' => 'b']]],
            'must_not' => [['term' => ['env' => 'dev']]],
        ]]);
        $this->assertSame(
            "\"level\" = 'ERROR' AND (\"service\" = 'a' OR \"service\" = 'b') AND NOT (\"env\" = 'dev')",
            $sql
        );
    }

    public function testOoInAndExists(): void
    {
        $in = OpenObserveAdapter::buildWhere(['terms' => ['level' => ['ERROR', 'FATAL']]]);
        $this->assertSame("\"level\" IN ('ERROR', 'FATAL')", $in);

        $exists = OpenObserveAdapter::buildWhere(['exists' => ['field' => 'user_id']]);
        $this->assertSame("\"user_id\" IS NOT NULL", $exists);
    }

    public function testOoSingleQuotesEscaped(): void
    {
        $sql = OpenObserveAdapter::buildWhere(['term' => ['msg' => "it's fine"]]);
        $this->assertSame("\"msg\" = 'it''s fine'", $sql);
    }

    public function testOoStreamNameMapping(): void
    {
        $this->assertSame('easylog_ws_tpc_202609', OpenObserveAdapter::toStreamName('easylog-ws-tpc-202609'));
        $this->assertSame('easylog_ws_tpc_', OpenObserveAdapter::toStreamName('easylog-ws-tpc-*'));
    }

    public function testOoToMicros(): void
    {
        $this->assertSame(1790294400000000, OpenObserveAdapter::toMicros('2026-09-25T00:00:00Z'));
    }

    // ---------- Quickwit：DSL → query string ----------

    public function testQwTermAndWildcard(): void
    {
        $this->assertSame('level:ERROR', QuickwitAdapter::buildQueryString(['term' => ['level' => 'ERROR']]));
        $this->assertSame('service:order-*', QuickwitAdapter::buildQueryString(['wildcard' => ['service' => ['value' => 'order-*']]]));
    }

    public function testQwPhraseQuoted(): void
    {
        $this->assertSame(
            'msg:"order created"',
            QuickwitAdapter::buildQueryString(['match' => ['msg' => ['query' => 'order created', 'operator' => 'and']]])
        );
    }

    public function testQwMatchAll(): void
    {
        $this->assertSame('*', QuickwitAdapter::buildQueryString(['match_all' => new \stdClass()]));
    }

    public function testQwRangeOnTsIsEmpty(): void
    {
        // 时间过滤走 start/end_timestamp，不进查询串
        $this->assertSame('', QuickwitAdapter::buildQueryString(['range' => ['ts' => ['gte' => '2026-09-25T00:00:00Z']]]));
        // 其它字段范围进查询串
        $this->assertSame('size>=10', QuickwitAdapter::buildQueryString(['range' => ['size' => ['gte' => '10']]]));
    }

    public function testQwBool(): void
    {
        $qs = QuickwitAdapter::buildQueryString(['bool' => [
            'must' => [['term' => ['level' => 'ERROR']], ['term' => ['service' => 'order']]],
            'should' => [['term' => ['env' => 'prod']]],
            'must_not' => [['term' => ['region' => 'dev']]],
        ]]);
        $this->assertSame('level:ERROR AND service:order AND (env:prod) AND NOT (region:dev)', $qs);
    }

    public function testQwTerms(): void
    {
        $this->assertSame('level:(ERROR OR FATAL)', QuickwitAdapter::buildQueryString(['terms' => ['level' => ['ERROR', 'FATAL']]]));
    }

    public function testQwExists(): void
    {
        $this->assertSame('user_id:*', QuickwitAdapter::buildQueryString(['exists' => ['field' => 'user_id']]));
    }

    public function testQwToEpochSecs(): void
    {
        $this->assertSame(1790294400, QuickwitAdapter::toEpochSecs('2026-09-25T00:00:00Z'));
    }
}
