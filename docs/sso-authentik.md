# SSO 单点登录（authentik OpenID Connect）

默认关闭；通过 docker-compose environment 开启，未配置时行为与旧版完全一致（兼容旧版本向上自动升级）。

## 1. authentik 应用配置（关键）
- 类型：**OpenID Connect (OAuth2) provider**（不是 Forward Auth）
- Client ID / Client Secret：应用详情页查看
- **Redirect URIs（重定向 URL）**：`https://paccu.cn/mybili/auth/sso/callback`
- Sign-out redirect URIs：`https://paccu.cn/mybili/`
- OpenID 配置颁发者：`https://paccu.cn/authentik/application/o/mybili/`

## 2. compose 环境变量
```yml
services:
  mybili:
    environment:
      SSO_ENABLED: "true"
      SSO_ISSUER: https://paccu.cn/authentik/application/o/mybili/
      SSO_CLIENT_ID: zrskbJZ1Mv32EFptIyolHpLwx3BFbUntVHH3X6HD
      SSO_CLIENT_SECRET: <authentik 应用的 Client Secret>
      SSO_REDIRECT_URI: https://paccu.cn/mybili/auth/sso/callback
      SSO_POST_LOGOUT_REDIRECT_URI: https://paccu.cn/mybili/
```

## 3. nginx（子路径代理，剥前缀）
```nginx
location = /mybili { return 301 /mybili/; }
location /mybili/ {
    proxy_pass http://192.168.0.145:5151/;   # 末尾 "/" = 剥掉 /mybili 前缀
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```
注：应用内已实现 OIDC 登录，**无需** auth_request/Forward Auth 段（两者取其一即可）。

## 4. 行为说明
- 未登录访问页面 → 302 到 authentik 授权页，登录后票据 cookie（HMAC 签名、默认 24h）回站
- 未登录访问 API → 401 JSON `{sso_redirect: "/auth/sso/redirect"}`
- 登出：`/auth/sso/logout`（清本地票据并跳 authentik end_session）
- 端点：`/auth/sso/redirect`、`/auth/sso/callback`、`/auth/sso/logout`（中间件白名单）
