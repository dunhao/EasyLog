<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 把 ingestion_endpoints.config 里已存在的明文 JSON 转为加密存储。
 *
 * 配套变更：IngestionEndpoint::$casts 的 config 由 'array' 改为 'encrypted:array'。
 * 若不跑本迁移，历史明文行在读取时会因解密失败而抛 DecryptException。
 *
 * 幂等：已加密的行（Crypt::decryptString 成功）直接跳过，可重复执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('ingestion_endpoints') || !$schema->hasColumn('ingestion_endpoints', 'config')) {
            return;
        }

        DB::table('ingestion_endpoints')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $raw = $row->config;
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    if ($this->isEncrypted($raw)) {
                        continue;
                    }

                    // 只转换合法 JSON 明文；非 JSON 的历史脏数据保持原样，避免丢数据
                    json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    DB::table('ingestion_endpoints')
                        ->where('id', $row->id)
                        ->update(['config' => Crypt::encryptString($raw)]);
                }
            });
    }

    public function down(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('ingestion_endpoints') || !$schema->hasColumn('ingestion_endpoints', 'config')) {
            return;
        }

        DB::table('ingestion_endpoints')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $raw = $row->config;
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    try {
                        $plain = Crypt::decryptString($raw);
                    } catch (\Throwable) {
                        continue; // 本来就是明文
                    }

                    DB::table('ingestion_endpoints')
                        ->where('id', $row->id)
                        ->update(['config' => $plain]);
                }
            });
    }

    private function isEncrypted(string $raw): bool
    {
        try {
            Crypt::decryptString($raw);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
