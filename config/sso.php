<?php

/**
 * SSO 单点登录配置（authentik OpenID Connect）。
 *
 * 全部由 docker-compose environment 驱动、默认关闭（向后兼容，零行为变化）：
 *   SSO_ENABLED=true
 *   SSO_ISSUER=https://paccu.cn/authentik/application/o/mybili/
 *   SSO_CLIENT_ID=<authentik 应用 Client ID>
 *   SSO_CLIENT_SECRET=<authentik 应用 Client Secret>
 *   SSO_REDIRECT_URI=https://paccu.cn/mybili/auth/sso/callback   ← 外部完整地址（含子路径）
 *   SSO_POST_LOGOUT_REDIRECT_URI=https://paccu.cn/mybili/
 *
 * 端点按 authentik OpenID 约定由 issuer 派生：
 *   authorize/  token/  userinfo/  end_session/
 */
return [
    'enabled' => env('SSO_ENABLED', false),

    // 颁发者（末尾统一补 /）
    'issuer' => rtrim((string) env('SSO_ISSUER', ''), '/').'/',

    'client_id' => env('SSO_CLIENT_ID', ''),
    'client_secret' => env('SSO_CLIENT_SECRET', ''),

    // 回调地址：必须与 authentik 登记的 Redirect URI 完全一致（含 https:// 与子路径）。
    // 未配置时回退 APP_URL + /auth/sso/callback（APP_URL 需含子路径才能自动正确）。
    'redirect_uri' => env('SSO_REDIRECT_URI', ''),

    // 登出后跳转（可选）
    'post_logout_redirect_uri' => env('SSO_POST_LOGOUT_REDIRECT_URI', ''),

    'scope' => env('SSO_SCOPE', 'openid profile email'),

    // 登录票据有效期（小时）：票据为 HMAC 签名 cookie，过期后重新走 SSO
    'ticket_ttl_hours' => (int) env('SSO_TICKET_TTL_HOURS', 24),
];
