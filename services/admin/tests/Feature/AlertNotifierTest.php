<?php

declare(strict_types=1);

namespace Tests\Feature;

use EasyLog\Mail\AlertEventMail;
use EasyLog\Models\AlertEvent;
use EasyLog\Models\AlertRule;
use EasyLog\Services\Alert\AlertNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * 告警通知投递器测试（webhook 成功/失败、email 未实现、无渠道）
 */
class AlertNotifierTest extends AdminApiTestCase
{
    public function testWebhookDispatchMarksSent(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $event = $this->createEvent('https://hooks.example.com/ops');

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertTrue($result);
        $this->assertSame('sent', $event->fresh()->notify_status);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/ops'
                && str_contains($request['text'], 'CRITICAL');
        });
    }

    public function testWebhookNon2xxMarksFailed(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::response(['error' => 'nope'], 500),
        ]);

        $event = $this->createEvent('https://hooks.example.com/ops');

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertFalse($result);
        $this->assertSame('failed', $event->fresh()->notify_status);
    }

    public function testInvalidWebhookUrlMarksFailed(): void
    {
        $event = $this->createEvent('not-a-url');

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertFalse($result);
        $this->assertSame('failed', $event->fresh()->notify_status);
        Http::assertNothingSent();
    }

    public function testEmailChannelDispatchesMail(): void
    {
        Mail::fake();

        $event = $this->createEventWithChannels([
            ['type' => 'email', 'name' => 'oncall', 'config' => ['to' => ['ops@example.com']]],
        ]);

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertTrue($result);
        $this->assertSame('sent', $event->fresh()->notify_status);
        Mail::assertSent(AlertEventMail::class, function (AlertEventMail $mail) {
            return $mail->hasTo('ops@example.com')
                && str_contains((string) $mail->envelope()->subject, 'EasyLog');
        });
    }

    public function testEmailChannelAcceptsCommaSeparatedString(): void
    {
        Mail::fake();

        $event = $this->createEventWithChannels([
            ['type' => 'email', 'config' => ['to' => 'a@example.com, b@example.com']],
        ]);

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertTrue($result);
        Mail::assertSent(AlertEventMail::class, function (AlertEventMail $mail) {
            return $mail->hasTo('a@example.com') && $mail->hasTo('b@example.com');
        });
    }

    public function testEmailChannelWithoutRecipientsMarksFailed(): void
    {
        Mail::fake();

        $event = $this->createEventWithChannels([
            ['type' => 'email', 'config' => []],
        ]);

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertFalse($result);
        $this->assertSame('failed', $event->fresh()->notify_status);
        Mail::assertNothingSent();
    }

    public function testNoChannelsMarksSentWithoutHttp(): void
    {
        $event = $this->createEventWithChannels([]);

        $result = (new AlertNotifier())->dispatch($event);

        $this->assertTrue($result);
        $this->assertSame('sent', $event->fresh()->notify_status);
        Http::assertNothingSent();
    }

    public function testDispatchPendingProcessesBatch(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->createEvent('https://hooks.example.com/a');
        $this->createEvent('https://hooks.example.com/b');

        $result = (new AlertNotifier())->dispatchPending();

        $this->assertSame(2, $result['dispatched']);
        $this->assertSame(0, $result['failed']);
    }

    // ---------- helpers ----------

    private function createEvent(string $url): AlertEvent
    {
        return $this->createEventWithChannels([
            ['type' => 'webhook', 'name' => 'ops', 'config' => ['url' => $url]],
        ]);
    }

    private function createEventWithChannels(array $channels): AlertEvent
    {
        $rule = AlertRule::create([
            'uuid'            => (string) Str::uuid(),
            'workspace_id'    => $this->workspaceId,
            'topic_id'        => $this->topicId,
            'name'            => '错误率告警',
            'condition'       => ['metric' => 'count'],
            'window_sec'      => 60,
            'threshold'       => 10,
            'severity'        => 'critical',
            'notify_channels' => $channels,
            'status'          => 'active',
        ]);

        return AlertEvent::create([
            'uuid'          => (string) Str::uuid(),
            'rule_id'       => $rule->id,
            'fired_at'      => now(),
            'severity'      => 'critical',
            'context'       => ['count' => 100],
            'notify_status' => 'pending',
        ]);
    }
}
