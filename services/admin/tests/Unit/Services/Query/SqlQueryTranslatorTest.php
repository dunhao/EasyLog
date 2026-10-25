<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Query;

use EasyLog\Services\Query\SqlParseException;
use EasyLog\Services\Query\SqlQueryTranslator;
use PHPUnit\Framework\TestCase;

/**
 * SQL → DSL 转译器测试
 */
class SqlQueryTranslatorTest extends TestCase
{
    private SqlQueryTranslator $t;

    protected function setUp(): void
    {
        $this->t = new SqlQueryTranslator();
    }

    public function testLooksLikeSql(): void
    {
        $this->assertTrue(SqlQueryTranslator::looksLikeSql("SELECT * FROM x"));
        $this->assertTrue(SqlQueryTranslator::looksLikeSql("  select level from x"));
        $this->assertFalse(SqlQueryTranslator::looksLikeSql('level:ERROR AND service:order'));
        $this->assertFalse(SqlQueryTranslator::looksLikeSql(''));
    }

    public function testSimpleWhereAndLimit(): void
    {
        $r = $this->t->translate("SELECT * FROM logs WHERE level = 'ERROR' LIMIT 50", []);
        $this->assertFalse($r['aggregate']);
        $dsl = $r['dsl'];

        $this->assertSame(50, $dsl['size']);
        $this->assertSame([['ts' => ['order' => 'desc']]], $dsl['sort']);
        $must = $dsl['query']['bool']['must'];
        $this->assertSame(['term' => ['level' => 'ERROR']], $must[0]);
    }

    public function testProjectionBecomesSource(): void
    {
        $r = $this->t->translate('SELECT msg, level FROM logs', []);
        $this->assertSame(['msg', 'level'], $r['dsl']['_source']);
    }

    public function testComparisonOperators(): void
    {
        $r = $this->t->translate(
            "SELECT * FROM t WHERE size > 10 AND size >= 20 AND size < 30 AND size <= 40 AND service != 'order-api'",
            []
        );
        $must = $r['dsl']['query']['bool']['must'];

        $this->assertSame(['range' => ['size' => ['gt' => '10']]], $must[0]);
        $this->assertSame(['range' => ['size' => ['gte' => '20']]], $must[1]);
        $this->assertSame(['range' => ['size' => ['lt' => '30']]], $must[2]);
        $this->assertSame(['range' => ['size' => ['lte' => '40']]], $must[3]);
        $this->assertSame(['bool' => ['must_not' => [['term' => ['service' => 'order-api']]]]], $must[4]);
    }

    public function testLikeBecomesWildcard(): void
    {
        $r = $this->t->translate("SELECT * FROM t WHERE service LIKE 'order-%' AND msg LIKE '%timeout%'", []);
        $must = $r['dsl']['query']['bool']['must'];

        $this->assertSame(['wildcard' => ['service' => ['value' => 'order-*']]], $must[0]);
        $this->assertSame(['wildcard' => ['msg' => ['value' => '*timeout*']]], $must[1]);
    }

    public function testInAndBetweenAndNull(): void
    {
        $r = $this->t->translate(
            "SELECT * FROM t WHERE level IN ('ERROR','FATAL') AND size BETWEEN 5 AND 10 AND trace_id IS NULL AND user_id IS NOT NULL",
            []
        );
        $must = $r['dsl']['query']['bool']['must'];

        $this->assertSame(['terms' => ['level' => ['ERROR', 'FATAL']]], $must[0]);
        $this->assertSame(['range' => ['size' => ['gte' => '5', 'lte' => '10']]], $must[1]);
        $this->assertSame(['bool' => ['must_not' => [['exists' => ['field' => 'trace_id']]]]], $must[2]);
        $this->assertSame(['exists' => ['field' => 'user_id']], $must[3]);
    }

    public function testOrNotAndParens(): void
    {
        $r = $this->t->translate(
            "SELECT * FROM t WHERE (level = 'ERROR' OR level = 'FATAL') AND NOT service = 'dev'",
            []
        );
        $must = $r['dsl']['query']['bool']['must'];

        $this->assertArrayHasKey('bool', $must[0]);
        $this->assertSame(1, $must[0]['bool']['minimum_should_match']);
        $this->assertCount(2, $must[0]['bool']['should']);
        // NOT service = 'dev' → must_not 包裹（NOT 作用域整体否定）
        $this->assertSame(
            ['bool' => ['must_not' => [['bool' => ['must' => [['term' => ['service' => 'dev']]]]]]]],
            $must[1]
        );
    }

    public function testNotAppliesToWholeAndGroup(): void
    {
        // NOT(a AND b) ≠ NOT a AND NOT b
        $r = $this->t->mustClauses("SELECT * FROM t WHERE NOT level = 'ERROR' AND service = 'x'");
        // NOT 只作用于紧随的比较表达式：NOT(level=ERROR) AND service=x
        $this->assertSame(
            ['bool' => ['must_not' => [['bool' => ['must' => [['term' => ['level' => 'ERROR']]]]]]]],
            $r[0]
        );
        $this->assertSame(['term' => ['service' => 'x']], $r[1]);
    }

    public function testOrderByOverridesDefault(): void
    {
        $r = $this->t->translate('SELECT * FROM t ORDER BY ts ASC, level DESC LIMIT 10', []);
        $this->assertSame([
            ['ts' => ['order' => 'asc']],
            ['level' => ['order' => 'desc']],
        ], $r['dsl']['sort']);
    }

    public function testCountStarIsAggregate(): void
    {
        $r = $this->t->translate("SELECT count(*) FROM t WHERE level = 'ERROR'", []);
        $this->assertTrue($r['aggregate']);
        $this->assertSame(0, $r['dsl']['size']);
        $this->assertSame(['result' => ['value_count' => ['field' => '_ts']]], $r['dsl']['aggs']);
    }

    public function testCountDistinctIsCardinality(): void
    {
        $r = $this->t->translate('SELECT count(DISTINCT user_id) FROM t', []);
        $this->assertSame(['result' => ['cardinality' => ['field' => 'user_id']]], $r['dsl']['aggs']);
    }

    public function testAvgIsAggregate(): void
    {
        $r = $this->t->translate('SELECT avg(response_time) FROM t', []);
        $this->assertSame(['result' => ['avg' => ['field' => 'response_time']]], $r['dsl']['aggs']);
    }

    public function testGroupByIsTermsAggregate(): void
    {
        $r = $this->t->translate('SELECT count(*), level FROM t GROUP BY level', []);
        $this->assertTrue($r['aggregate']);
        $this->assertSame(['result' => ['terms' => ['field' => 'level', 'size' => 10]]], $r['dsl']['aggs']);
    }

    public function testGroupByWithMetricSubAgg(): void
    {
        $r = $this->t->translate('SELECT avg(response_time), service FROM t GROUP BY service', []);
        $agg = $r['dsl']['aggs']['result'];
        $this->assertSame(['field' => 'service', 'size' => 10], $agg['terms']);
        $this->assertSame(['metric' => ['avg' => ['field' => 'response_time']]], $agg['aggs']);
    }

    public function testTimeContextGoesToFilter(): void
    {
        $r = $this->t->translate('SELECT * FROM t', [
            'from' => '2026-09-25T00:00:00Z',
            'to'   => '2026-09-25T01:00:00Z',
        ]);
        $filter = $r['dsl']['query']['bool']['filter'];
        $this->assertSame(['range' => ['ts' => ['gte' => '2026-09-25T00:00:00Z', 'lte' => '2026-09-25T01:00:00Z']]], $filter[0]);
    }

    public function testPhraseEqualityBecomesMatch(): void
    {
        $r = $this->t->translate("SELECT * FROM t WHERE msg = 'order created successfully'", []);
        $this->assertSame(
            ['match' => ['msg' => ['query' => 'order created successfully', 'operator' => 'and']]],
            $r['dsl']['query']['bool']['must'][0]
        );
    }

    public function testSyntaxErrors(): void
    {
        $this->expectException(SqlParseException::class);
        $this->t->translate('SELECT FROM WHERE');
    }

    public function testUnsupportedAggregateFunctionThrows(): void
    {
        $this->expectException(SqlParseException::class);
        $this->t->translate('SELECT median(response_time) FROM t');
    }

    public function testTrailingGarbageThrows(): void
    {
        $this->expectException(SqlParseException::class);
        $this->t->translate("SELECT * FROM t WHERE level = 'ERROR' extra junk");
    }

    public function testMustClausesForHistogram(): void
    {
        $must = $this->t->mustClauses("SELECT * FROM t WHERE level = 'ERROR'");
        $this->assertSame([['term' => ['level' => 'ERROR']]], $must);
    }
}
