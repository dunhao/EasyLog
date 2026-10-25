<?php

namespace EasyLog\Support;

/**
 * 敏感字段脱敏。
 *
 * 背景：管理面 API 曾把 endpoint 的 config（MQ / SMTP 连接凭据）与告警规则的
 * notify_channels 原样返回给前端，任何已登录用户都能读到全租户的明文密码。
 * 落库已改为 encrypted:array，但「能解密」不等于「该返回」——API 出网前仍需脱敏。
 *
 * 策略：保留完整键结构（前端表单仍能渲染），只对敏感值打码。
 */
class SensitiveMasker
{
    public const MASK = '***';

    /**
     * 命中即打码的键名（不区分大小写，子串匹配）。
     */
    private const SENSITIVE_KEY = '/(password|passwd|pass|secret|token|api[-_]?key|access[-_]?key|credential|dsn|ca[-_]?cert|cert[-_]?content|private[-_]?key|auth)/i';

    /**
     * URL 类字段：保留 scheme://host/path，query / userinfo 打码。
     * webhook url 常把 token 写在 query 里，整段回显等于泄露凭据。
     */
    private const URL_KEY = '/(url|uri|endpoint|webhook|host|broker|brokers|server|address)/i';

    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::maskArray($value);
        }

        return $value;
    }

    public static function maskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskArray($value);
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            if (preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = self::maskScalar($value);
                continue;
            }

            if (is_string($value) && preg_match(self::URL_KEY, $key)) {
                $data[$key] = self::maskUrl($value);
            }
        }

        return $data;
    }

    private static function maskScalar(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value; // 空值无需打码，避免前端误判为「已配置」
        }

        return self::MASK;
    }

    private static function maskUrl(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // 不是 URL 形态（如 brokers = "host:9092"）就原样返回
        if (!str_contains($value, '://')) {
            return $value;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return self::MASK;
        }

        $out = ($parts['scheme'] ?? '') . '://';
        if (!empty($parts['host'])) {
            if (!empty($parts['user'])) {
                $out .= self::MASK . '@';
            }
            $out .= $parts['host'];
            if (!empty($parts['port'])) {
                $out .= ':' . $parts['port'];
            }
        }
        $out .= $parts['path'] ?? '';

        if (!empty($parts['query'])) {
            $out .= '?' . self::MASK;
        }
        if (!empty($parts['fragment'])) {
            $out .= '#' . self::MASK;
        }

        return $out;
    }
}
