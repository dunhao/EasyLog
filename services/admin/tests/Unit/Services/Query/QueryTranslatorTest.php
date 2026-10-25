<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Query;

use EasyLog\Services\Query\QueryTranslator;
use PHPUnit\Framework\TestCase;

/**
 * 查询字符串 → ES DSL 转译器测试
 */
class QueryTranslatorTest extends TestCase
{
    private QueryTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new QueryTranslator();
    }

    public function testEmptyQueryYieldsMatchAll(): void
    {
        $dsl = $this->translator->translate('');
        $this->assertEquals([['match_all' => new \stdClass()]], $dsl['query']['bool']['must']);
    }

    public function testBareKeywordGoesToMsgField(): void
    {
        $dsl = $this->translator->translate('order failed');
        $this->assertEquals(
            [['match' => ['msg' => 'order failed']]],
            $dsl['query']['bool']['must']
        );
    }

    public function testFieldValueYieldsTerm(): void
    {
        $dsl = $this->translator->translate('level:ERROR');
        $this->assertEquals(
            [['term' => ['level' => 'ERROR']]],
            $dsl['query']['bool']['must']
        );
    }

    public function testPhraseValueYieldsMatchWithAndOperator(): void
    {
        $dsl = $this->translator->translate('msg:"order created"');
        $this->assertEquals(
            [['match' => ['msg' => ['query' => 'order created', 'operator' => 'and']]]],
            $dsl['query']['bool']['must']
        );
    }

    public function testWildcardValueYieldsWildcard(): void
    {
        $dsl = $this->translator->translate('service:order-*');
        $this->assertEquals(
            [['wildcard' => ['service' => ['value' => 'order-*']]]],
            $dsl['query']['bool']['must']
        );
    }

    public function testAndCombinesClausesIntoMust(): void
    {
        $dsl = $this->translator->translate('level:ERROR AND service:order');
        $this->assertCount(2, $dsl['query']['bool']['must']);
        $this->assertEquals(
            ['term' => ['level' => 'ERROR']],
            $dsl['query']['bool']['must'][0]
        );
        $this->assertEquals(
            ['term' => ['service' => 'order']],
            $dsl['query']['bool']['must'][1]
        );
    }

    public function testOrYieldsShouldWithMinimumOne(): void
    {
        $dsl = $this->translator->translate('level:ERROR OR level:FATAL');
        $bool = $dsl['query']['bool']['must'][0]['bool'];
        $this->assertArrayHasKey('should', $bool);
        $this->assertEquals(2, count($bool['should']));
        $this->assertEquals(1, $bool['minimum_should_match']);
    }

    public function testNotYieldsMustNot(): void
    {
        $dsl = $this->translator->translate('NOT level:INFO');
        $this->assertEquals(
            [['bool' => ['must_not' => [['term' => ['level' => 'INFO']]]]]],
            $dsl['query']['bool']['must']
        );
    }

    public function testParenthesizedGroupCombinesWithAnd(): void
    {
        $dsl = $this->translator->translate('(level:ERROR OR level:FATAL) AND service:order');
        $must = $dsl['query']['bool']['must'];
        $this->assertCount(2, $must);
        // 第一项是分组 bool，第二项是 service term
        $this->assertArrayHasKey('bool', $must[0]);
        $this->assertEquals(['term' => ['service' => 'order']], $must[1]);
    }

    public function testRangeSyntaxYieldsRangeClause(): void
    {
        $dsl = $this->translator->translate('level:[ERROR TO FATAL]');
        $this->assertEquals(
            [['range' => ['level' => ['gte' => 'ERROR', 'lte' => 'FATAL']]]],
            $dsl['query']['bool']['must']
        );
    }

    public function testTimeRangeBecomesFilter(): void
    {
        $dsl = $this->translator->translate('level:ERROR', [
            'from' => '2026-09-01T00:00:00Z',
            'to'   => '2026-09-02T00:00:00Z',
        ]);
        $this->assertEquals(
            [['range' => ['ts' => ['gte' => '2026-09-01T00:00:00Z', 'lte' => '2026-09-02T00:00:00Z']]]],
            $dsl['query']['bool']['filter']
        );
    }

    public function testSizeAndOffsetAreClamped(): void
    {
        $dsl = $this->translator->translate('', ['size' => 5000, 'from_offset' => -5]);
        $this->assertEquals(1000, $dsl['size']);
        $this->assertEquals(0, $dsl['from']);
    }

    public function testDefaultSortIsTsDesc(): void
    {
        $dsl = $this->translator->translate('');
        $this->assertEquals([['ts' => ['order' => 'desc']]], $dsl['sort']);
    }

    public function testFieldsRestrictsSource(): void
    {
        $dsl = $this->translator->translate('', ['fields' => ['ts', 'level']]);
        $this->assertEquals(['ts', 'level'], $dsl['_source']);
    }

    public function testHistogramBuildsDateHistogram(): void
    {
        $dsl = $this->translator->histogram('level:ERROR', ['bucket' => 'hour']);
        $this->assertEquals('1h', $dsl['aggs']['histogram']['date_histogram']['calendar_interval']);
        $this->assertEquals('ts', $dsl['aggs']['histogram']['date_histogram']['field']);
        $this->assertEquals(0, $dsl['size']);
    }

    public function testAggregateSupportsTermsAvgAndCardinality(): void
    {
        $terms = $this->translator->aggregate('', ['agg_type' => 'terms', 'field' => 'service']);
        $this->assertEquals(['field' => 'service', 'size' => 10], $terms['aggs']['result']['terms']);

        $avg = $this->translator->aggregate('', ['agg_type' => 'avg', 'field' => 'latency_ms']);
        $this->assertEquals(['field' => 'latency_ms'], $avg['aggs']['result']['avg']);

        $card = $this->translator->aggregate('', ['agg_type' => 'cardinality', 'field' => 'user_id']);
        $this->assertEquals(['field' => 'user_id'], $card['aggs']['result']['cardinality']);
    }

    public function testUnknownAggTypeFallsBackToTerms(): void
    {
        $dsl = $this->translator->aggregate('', ['agg_type' => 'bogus', 'field' => 'level']);
        $this->assertArrayHasKey('terms', $dsl['aggs']['result']);
    }
}
