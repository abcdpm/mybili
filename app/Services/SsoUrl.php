<?php

namespace App\Services;

/**
 * SSO 子路径感知的站内 URL 生成（第 SSO 项·子路径部署修复）。
 *
 * 背景：nginx 剥前缀部署（/mybili/ → http://app/）下，应用内部路由是根路径，
 * 但浏览器侧的跳转必须携带 /mybili 前缀，否则 Location 丢失前缀 → 404。
 * 前缀来自 config('sso.base_path')（由 SSO_REDIRECT_URI 推导或 SSO_BASE_PATH 显式指定）。
 *
 * 生效条件：仅 SSO_ENABLED=true。子路径前缀是 SSO 门禁部署（nginx 剥前缀）的
 * 伴生物；SSO 关闭时用户内网直连根路径（如 http://ip:5151/），此时 compose
 * 残留的 SSO_REDIRECT_URI 推导出的 base_path 若继续生效，会给资源 URL 错误
 * 加前缀 → /mybili/build/... 404 → 白屏（2026-10-26 线上二次实测）。
 */
final class SsoUrl
{
    /** 子路径前缀：'/mybili' 或 ''（SSO 关闭 / 根部署） */
    public static function base(): string
    {
        // SSO 关闭 → 一切子路径语义失效（所有消费方的唯一闸门：
        // 资源前缀中间件、welcome 注入、门禁跳转、本地登录表单）
        if (! config('sso.enabled')) {
            return '';
        }

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
