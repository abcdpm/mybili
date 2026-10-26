<?php

namespace App\Console\Commands;

use App\Services\LocalLoginService;
use Illuminate\Console\Command;

/**
 * SSO 降级本地登录密码管理（应急通道）。
 *
 * 密码由系统自动生成并保存在本地持久化空间（默认主库同目录），
 * 本命令用于查看路径/密码与轮换，避免手工翻文件。
 */
class SsoLocalPassword extends Command
{
    protected $signature = 'app:sso-local-password
                                {--rotate : 轮换（重新生成）密码}';

    protected $description = '查看/轮换 SSO 降级本地登录密码（应急通道）';

    public function handle(LocalLoginService $service): int
    {
        if ($this->option('rotate')) {
            $password = $service->rotate();
            $this->info('已轮换本地登录密码：');
        } else {
            $password = $service->ensurePassword();
            $this->info('本地登录密码（不存在时已自动生成）：');
        }

        $this->line('  密码: '.$password);
        $this->line('  文件: '.$service->passwordFilePath());
        $this->comment('登录入口（隐藏，需携带激活参数）: /auth/local?local=true');

        return 0;
    }
}
