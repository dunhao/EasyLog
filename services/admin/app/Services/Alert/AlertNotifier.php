<?php

declare(strict_types=1);

namespace EasyLog\Services\Alert;

use EasyLog\Mail\AlertEventMail;
use EasyLog\Models\AlertEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 告警通知投递器
 *
 * 消费 notify_status=pending 的 AlertEvent，按规则 notify_channels 投递：
 *   - webhook：HTTP POST JSON（Slack / 钉钉 / 企微 / 通用 webhook 均为 URL 变体）
 *   - email：SMTP 发送（全局 MAIL_* 配置；渠道 config.smtp 可按规则覆盖 host/port/账号）
 *
 * 渠道配置结构（alert_rules.notify_channels）：
 *   [
 *     {"type": "webhook", "name": "ops", "config": {"url": "https://hooks.slack.com/..."}},
 *     {"type": "email", "name": "oncall", "config": {"to": ["ops@example.com"]}}
 *   ]
 */
final class AlertNotifier
{
    /** 单次批处理上限，避免一次拉起过多外部请求 */
    private const BATCH_LIMIT = 100;

    /**
     * 投递所有 pending 事件
     *
     * @return array{dispatched:int,failed:int} 成功/失败事件数
     */
    public function dispatchPending(int $limit = self::BATCH_LIMIT): array
    {
        $events = AlertEvent::with('rule')
            ->where('notify_status', 'pending')
            ->orderBy('fired_at')
            ->limit(max(1, $limit))
            ->get();

        $dispatched = 0;
        $failed = 0;

        foreach ($events as $event) {
            if ($this->dispatch($event)) {
                $dispatched++;
            } else {
                $failed++;
            }
        }

        return ['dispatched' => $dispatched, 'failed' => $failed];
    }

    /**
     * 投递单个事件；全部渠道成功才算成功
     */
    public function dispatch(AlertEvent $event): bool
    {
        $channels = $event->rule?->notify_channels ?? [];
        if (empty($channels)) {
            Log::info('EasyLog alert event has no channels, marking sent', ['event' => $event->uuid]);
            $event->update(['notify_status' => 'sent']);
            return true;
        }

        $ok = true;
        foreach ($channels as $channel) {
            try {
                $result = $this->dispatchToChannel($event, $channel);
            } catch (\Throwable $e) {
                Log::error('EasyLog alert channel dispatch error', [
                    'event' => $event->uuid,
                    'error' => $e->getMessage(),
                ]);
                $result = false;
            }
            $ok = $ok && $result;
        }

        $event->update(['notify_status' => $ok ? 'sent' : 'failed']);
        return $ok;
    }

    private function dispatchToChannel(AlertEvent $event, array $channel): bool
    {
        $type = $channel['type'] ?? 'webhook';
        $config = $channel['config'] ?? [];

        return match ($type) {
            'webhook' => $this->dispatchWebhook($event, $config),
            'email'   => $this->dispatchEmail($event, $config),
            default   => $this->unsupported($type),
        };
    }

    private function dispatchWebhook(AlertEvent $event, array $config): bool
    {
        $url = $config['url'] ?? '';
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            Log::warning('EasyLog alert webhook missing valid url', ['event' => $event->uuid]);
            return false;
        }

        $response = $this->http()->timeout(10)->post($url, [
            'text'     => sprintf('[%s] %s', strtoupper($event->severity), $event->rule?->name ?? 'alert'),
            'severity' => $event->severity,
            'fired_at' => $event->fired_at?->toISOString(),
            'context'  => $event->context,
        ]);

        $ok = $response->successful();
        if (!$ok) {
            Log::warning('EasyLog alert webhook non-2xx', [
                'event' => $event->uuid,
                'status' => $response->status(),
            ]);
        }
        return $ok;
    }

    private function dispatchEmail(AlertEvent $event, array $config): bool
    {
        $to = $config['to'] ?? [];
        // 兼容 "a@x.com,b@x.com" 与 ['a@x.com', 'b@x.com'] 两种写法
        if (is_string($to)) {
            $to = array_map('trim', explode(',', $to));
        }
        $to = array_values(array_filter(array_map('strval', (array) $to)));
        if (empty($to)) {
            Log::warning('EasyLog alert email channel missing recipients', ['event' => $event->uuid]);
            return false;
        }

        // 渠道级 SMTP 覆盖（可选）：config.smtp = {host, port, username, password}
        // 未配置时走全局 mailer（MAIL_* 环境变量）
        if (!empty($config['smtp']['host'])) {
            config([
                'mail.mailers.easylog_alert' => [
                    'transport' => 'smtp',
                    'host'      => $config['smtp']['host'],
                    'port'      => (int) ($config['smtp']['port'] ?? 587),
                    'username'  => $config['smtp']['username'] ?? null,
                    'password'  => $config['smtp']['password'] ?? null,
                    'timeout'   => null,
                ],
            ]);
            Mail::mailer('easylog_alert')->to($to)->send(new AlertEventMail($event));
        } else {
            Mail::to($to)->send(new AlertEventMail($event));
        }

        Log::info('EasyLog alert email dispatched', ['event' => $event->uuid, 'to' => $to]);
        return true;
    }

    private function unsupported(string $type): bool
    {
        Log::warning('EasyLog alert unsupported channel type', ['type' => $type]);
        return false;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson();
    }
}
