<?php

declare(strict_types=1);

namespace EasyLog\SDK;

/**
 * 单条日志的归一化结构。
 *
 * - msg:    必填，人类可读消息
 * - level:  日志级别（DEBUG/INFO/WARN/ERROR/FATAL/TRACE），默认 INFO
 * - ts:     ISO8601 字符串；为空时由服务端补齐
 * - fields: 业务上下文（任意 KV，最终 JSON 存储可查询）
 */
class LogEntry
{
    public string $msg;
    public string $level;
    public ?string $ts;
    public array $fields;

    public function __construct(string $msg, string $level = 'INFO', ?string $ts = null, array $fields = [])
    {
        $this->msg    = $msg;
        $this->level  = $level !== '' ? strtoupper($level) : 'INFO';
        $this->ts     = $ts;
        $this->fields = $fields;
    }

    /**
     * 转为服务端 batch 接口期望的数组结构。
     */
    public function toArray(): array
    {
        $arr = [
            'level' => $this->level,
            'msg'   => $this->msg,
        ];
        if ($this->ts !== null && $this->ts !== '') {
            $arr['ts'] = $this->ts;
        }
        foreach ($this->fields as $k => $v) {
            // 不允许覆盖内置字段
            if (!array_key_exists($k, $arr)) {
                $arr[$k] = $v;
            }
        }
        return $arr;
    }
}
