<?php

declare(strict_types=1);

namespace EasyLog\Console\Commands;

use EasyLog\Models\User;
use Illuminate\Console\Command;

/**
 * 为指定邮箱签发 Sanctum 访问令牌（管理面 API 鉴权用）
 *
 * 用法：
 *   php artisan easylog:token admin@easylog.local
 *   php artisan easylog:token admin@easylog.local --name=console --abilities=*
 *
 * 拿到令牌后：
 *   curl -H 'Authorization: Bearer <token>' \
 *        -H 'Accept: application/json' \
 *        http://<host>:9515/api/v1/workspaces
 */
class MakeApiTokenCommand extends Command
{
    protected $signature = 'easylog:token
                             {email : 用户邮箱}
                             {--name=cli : 令牌名称}
                             {--abilities=* : 权限（默认 *）}';

    protected $description = '为用户签发 Sanctum API 令牌';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        /** @var User|null $user */
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("User not found: {$email}");
            $this->line('Available users:');
            foreach (User::query()->select('id', 'email')->get() as $u) {
                $this->line(sprintf('  #%d  %s', $u->id, $u->email));
            }
            return self::FAILURE;
        }

        $abilities = (array) $this->option('abilities');
        if ($abilities === []) {
            $abilities = ['*'];
        }

        $token = $user->createToken((string) $this->option('name'), $abilities);

        $this->info('Token issued.');
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->comment('请立刻保存：明文令牌只显示这一次。');

        return self::SUCCESS;
    }
}
