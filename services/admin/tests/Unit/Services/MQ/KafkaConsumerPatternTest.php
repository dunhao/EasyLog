<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MQ;

use EasyLog\Services\MQ\Consumers\KafkaConsumer;
use PHPUnit\Framework\TestCase;

/**
 * Kafka topic pattern 匹配测试（不依赖 rdkafka 扩展）
 */
class KafkaConsumerPatternTest extends TestCase
{
    public function testWildcardPattern(): void
    {
        $topics = KafkaConsumer::filterTopics(
            ['app-logs', 'app-metrics', 'myapp-logs', 'other'],
            'app-*'
        );
        $this->assertSame(['app-logs', 'app-metrics'], $topics);
    }

    public function testExactNameWithoutWildcard(): void
    {
        $topics = KafkaConsumer::filterTopics(
            ['order-logs', 'order-logs-v2', 'other'],
            'order-logs'
        );
        $this->assertSame(['order-logs'], $topics);
    }

    public function testRegexPatternWithCaretPrefix(): void
    {
        $topics = KafkaConsumer::filterTopics(
            ['svc-a-2026-09', 'svc-b-2026-09', 'svc-a-2025-01'],
            '^svc-a-\d{4}-\d{2}$'
        );
        $this->assertSame(['svc-a-2026-09', 'svc-a-2025-01'], $topics);
    }

    public function testMultiWildcardPattern(): void
    {
        $topics = KafkaConsumer::filterTopics(
            ['prod.app.error', 'prod.app.info', 'dev.app.error'],
            'prod.*.error'
        );
        $this->assertSame(['prod.app.error'], $topics);
    }

    public function testNoMatchReturnsEmpty(): void
    {
        $this->assertSame([], KafkaConsumer::filterTopics(['a', 'b'], 'zzz*'));
        $this->assertSame([], KafkaConsumer::filterTopics([], 'app-*'));
    }

    public function testPatternToRegexEscapesSpecialChars(): void
    {
        // 正则元字符按字面量处理
        $this->assertSame(1, preg_match(KafkaConsumer::patternToRegex('a.b+c(d)'), 'a.b+c(d)'));
        $this->assertSame(0, preg_match(KafkaConsumer::patternToRegex('a.b+c(d)'), 'aXb+c(d)'));
    }
}
