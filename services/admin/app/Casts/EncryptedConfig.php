<?php

namespace EasyLog\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * 加密的数组 cast（带明文兼容回退）。
 *
 * 为什么不用内置的 'encrypted:array'：
 * 内置 cast 在读到历史明文行时会直接抛 DecryptException，而本项目存在绕过模型
 * 直插该列的路径（seeder、部分批量写入、测试用例），硬切换会让这些行一读就炸。
 *
 * 本 Caster 的策略是渐进式迁移：
 * - set()：一律加密落库，新数据从写入那一刻起就是密文
 * - get()：先按密文解；解不开再按明文 JSON 解（兼容存量行）
 *
 * 存量明文由迁移 2026_09_30_000001_encrypt_existing_endpoint_config.php 逐步转换，
 * 转换完成后本 Caster 的明文分支自然不再命中，可安全移除。
 */
class EncryptedConfig implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): array
    {
        return $this->decode($value);
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (!is_array($value)) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        // 1) 密文路径
        try {
            $plain = Crypt::decryptString($value);
            $decoded = json_decode($plain, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable) {
            // 不是密文，落到明文分支
        }

        // 2) 明文兼容路径（存量行 / 绕过模型的写入）
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }
}
