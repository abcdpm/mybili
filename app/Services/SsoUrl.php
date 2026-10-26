<?php

namespace App\Services;

/**
 * SSO 子路径感知的站内 URL 生成（第 SSO 项·子路径部署修复）。
 *
 * 背景：nginx 剥前缀部署（/mybili/ → http://app/）下，应用内部路由是根路径，
 * 但浏览器侧的跳转必须携带 /mybili 前缀，否则 Location 丢失前缀 → 404。
 * 前缀来自 config('sso.base_path')（由 SSO_REDIRECT_URI 推导或 SSO_BASE_PATH 显式指定）。
 */
final class SsoUrl
{
    /** 子路径前缀：'/mybili' 或 ''（根部署） */
    public static function base(): string
    {
        return rtrim((string) config('sso.base_path', ''), '/');
    }

    /**
     * 生成浏览器侧地址：base + path（根部署时 base 为空、行为不变）。
     * 例：to('/auth/sso/redirect') → '/mybili/auth/sso/redirect'
     */
    public static function to(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }
}
