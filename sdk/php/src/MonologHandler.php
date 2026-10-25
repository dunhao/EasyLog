<?php

declare(strict_types=1);

namespace EasyLog\SDK;

use Monolog\Handler\AbstractHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\FormattableHandlerTrait;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Monolog Handler：将 Monolog 日志转发到 EasyLog。
 *
 * 使用方式：
 *   use Monolog\Logger;
 *   use EasyLog\SDK\MonologHandler;
 *
 *   $logger = new Logger('order-service');
 *   $logger->pushHandler(new MonologHandler([
 *       'app_id'     => 'app_x9f3k2',
 *       'app_secret'  => 'sec_xxx',
 *       'endpoint'   => 'https://log.example.com',
 *       'topic'      => 'order-logs',
 *       'batch_size' => 1000,
 *   ]));
 *
 *   $logger->info('order created', ['order_id' => 'A123']);
 *
 * 兼容 Monolog 2.x（LogRecord 数组）与 3.x（LogRecord 对象）。
 */
class MonologHandler extends AbstractHandler implements FormattableHandlerInterface
{
    use FormattableHandlerTrait;

    private Client $client;

    /**
     * 额外追加到 fields 的 channel 名称（即 monolog $logger name），
     * 作为字段 `service` 一起存入，便于在 EasyLog 中按服务筛选。
     */
    private ?string $serviceField = null;

    public function __construct(array $config, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->client = new Client(new Config($config));

        // 从 config 提取 service 字段（可选）
        $this->serviceField = $config['service'] ?? null;
    }

    /**
     * Monolog 3.x 入口（LogRecord 对象）。
     */
    public function handle(LogRecord|array $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        // Monolog 2.x：$record 是数组；3.x：LogRecord 对象
        if ($record instanceof LogRecord) {
            $level   = $record->level->getName();
            $message = $record->message;
            $context = $record->context;
            $channel = $record->channel;
            $ts      = $record->datetime->format('c');
        } else {
            $level   = $record['level_name'];
            $message = $record['message'];
            $context = $record['context'];
            $channel = $record['channel'] ?? 'app';
            $ts      = isset($record['datetime']) ? ($record['datetime'] instanceof \DateTimeInterface
                        ? $record['datetime']->format('c')
                        : (string) $record['datetime']) : null;
        }

        // 上下文 + 默认 service 字段
        $fields = $context;
        if ($this->serviceField !== null) {
            $fields['service'] = $this->serviceField;
        } elseif (!isset($fields['service'])) {
            $fields['service'] = $channel;
        }

        $this->client->log($level, $message, $fields, $ts);

        return !$this->bubble;
    }

    /**
     * 兼容 Monolog 2.x 的老接口；3.x 调用 handle()。
     */
    public function process(LogRecord|array $record): LogEntry
    {
        // 仅在确有需要时使用：返回 LogEntry 抽象
        if ($record instanceof LogRecord) {
            return new LogEntry(
                $record->message,
                $record->level->getName(),
                $record->datetime->format('c'),
                $record->context + ['service' => $record->channel]
            );
        }
        return new LogEntry(
            $record['message'],
            $record['level_name'],
            $record['datetime'] instanceof \DateTimeInterface
                ? $record['datetime']->format('c') : (string) $record['datetime'],
            ($record['context'] ?? []) + ['service' => $record['channel'] ?? 'app']
        );
    }

    /**
     * 退出前手动 flush（如果业务有显式 shutdown 流程）。
     */
    public function flush(): void
    {
        $this->client->flush();
    }
}
