<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * SSO 降级本地登录：密码生成与校验（第 SSO 项·应急通道）。
 *
 * 设计（公网安全）：
 *  - 密码随机生成（32 字节 base64url）并保存到"本地持久化空间"（默认与主库同目录，
 *    即 compose 的持久化卷内），重启/升级不丢；文件权限 0600、目录不在 Web 根下；
 *  - 仅生成一次、稳定复用；可通过 --rotate 轮换；
 *  - 校验用 SHA256 后 hash_equals（定长比较，抗时序侧信道）。
 */
class LocalLoginService
{
    /** 密码文件默认路径：主库同目录（必然持久化） */
    public function passwordFilePath(): string
    {
        $configured = (string) config('sso.local_password_file');

        return $configured !== ''
            ? $configured
            : dirname((string) config('database.connections.sqlite.database')).'/sso-local-password.txt';
    }

    /**
     * 确保密码存在：首次调用生成并持久化，之后稳定返回同一密码。
     */
    public function ensurePassword(): string
    {
        $path = $this->passwordFilePath();
        if (is_file($path)) {
            $stored = trim((string) File::get($path));
            if ($stored !== '') {
                return $stored;
            }
        }

        return $this->rotate();
    }

    /**
     * 轮换密码（重新生成并覆盖保存）。
     */
    public function rotate(): string
    {
        $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $path = $this->passwordFilePath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $password.PHP_EOL);
        @chmod($path, 0600);

        return $password;
    }

    /**
     * 定长比较校验（sha256 后 hash_equals）。
     */
    public function verify(string $input): bool
    {
        $stored = $this->ensurePassword();

        return hash_equals(hash('sha256', $stored), hash('sha256', trim($input)));
    }
}
