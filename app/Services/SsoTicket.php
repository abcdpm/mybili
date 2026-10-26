<?php

namespace App\Services;

/**
 * SSO 登录票据（HMAC 签名 cookie）。
 *
 * 设计取舍：票据不依赖 Laravel session —— web 与 api 两个中间件组都能校验
 * （api 组默认无 StartSession）；cookie 名加入 EncryptCookies 例外清单，
 * 两个组读到的明文一致。签名密钥 = app.key，伪造需先泄露应用密钥。
 */
final class SsoTicket
{
    public const COOKIE = 'sso_ticket';

    /**
     * 签发票据：base64(json{sub,name,email,exp}) + "." + HMAC-SHA256。
     */
    public static function make(array $user, int $ttlHours): string
    {
        $payload = base64_encode((string) json_encode([
            'sub' => (string) ($user['sub'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'exp' => time() + max(1, $ttlHours) * 3600,
        ], JSON_UNESCAPED_UNICODE));

        return $payload.'.'.hash_hmac('sha256', $payload, self::key());
    }

    /**
     * 校验票据：签名与有效期均通过返回用户数组，否则 null。
     */
    public static function verify(?string $ticket): ?array
    {
        if (! is_string($ticket) || $ticket === '') {
            return null;
        }
        [$payload, $sig] = array_pad(explode('.', $ticket, 2), 2, '');
        if ($payload === '' || $sig === '' || ! hash_equals(hash_hmac('sha256', $payload, self::key()), $sig)) {
            return null;
        }
        $data = json_decode(base64_decode($payload), true);
        if (! is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }
}
