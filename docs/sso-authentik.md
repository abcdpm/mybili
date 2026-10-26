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

## 5. SSO 故障降级：隐藏本地登录（应急通道）
SSO 挂了时用它救急，**主站无任何入口/提示**，必须直接访问带激活参数的地址：

```
https://paccu.cn/mybili/auth/local?local=true      ← 无 ?local=true 一律 404
```

短链兼容（等价入口）：
```
https://paccu.cn/mybili/?local=true
https://paccu.cn/mybili?local=true
```
## 6. 子路径部署（/mybili）注意事项
站内所有跳转地址由服务端**从 SSO_REDIRECT_URI 自动推导子路径前缀**（也可用
`SSO_BASE_PATH=/mybili` 显式指定）：登录入口、表单 action、错误回跳、登录成功
的 intended 回跳、API 的 sso_redirect 全部携带 `/mybili` 前缀 ——
否则 nginx 剥前缀部署下 `Location: /auth/sso/redirect` 会被浏览器解析到域名根
而 404（2026-10-26 实测踩坑记录）。

nginx 侧**不要**用 proxy_redirect 给 Location 补前缀（会误伤跳转 authentik 的绝对地址）。

安全边界：`?local=true` 仅在**站点根路径**生效；API/任意其它路径携带同参数不会绕过 SSO。

## 7. 端点解析与排错
端点地址优先走 **OIDC 发现协议**（`issuer/.well-known/openid-configuration`）——
authentik 公布的 authorization/token/userinfo/end_session 是什么就用什么（自动适配
应用 slug / 子路径部署 / 版本差异，结果缓存 1 小时）；发现失败时回退按 issuer
派生后缀。

跳 authentik 报 404 的排查顺序（2026-10-26 实测）：
```bash
# 1. 发现文档是否可用（返回 JSON 即端点正常，看 authorization_endpoint 字段）
curl -s https://paccu.cn/authentik/application/o/mybili/.well-known/openid-configuration
```
- 404 → 依次检查：
  1. **nginx 对 /authentik 是否剥前缀**：`proxy_pass http://authentik:9000/;`（末尾 `/`）
     必须带，否则 authentik 收到 `/authentik/application/o/...` → 无此路由 → 404；
  2. **Provider 类型必须是 OpenID Connect(OAuth2)**，Forward-auth 类型没有 `/application/o/` 端点；
  3. **应用 slug 是否为 `mybili`**（`/application/o/<slug>/` 中是应用 slug）。

- **密码自动生成并保存**在本地持久化空间（默认与主库同目录，即
  `/data/sso-local-password.txt`），首次进入登录页时生成、之后稳定复用；
  文件权限 0600、不在 Web 根下
- 查看/轮换密码：
  ```bash
  docker exec mybili php artisan app:sso-local-password            # 查看（无则生成）
  docker exec mybili php artisan app:sso-local-password --rotate   # 轮换
  ```
- 登录成功签发**与 SSO 相同的票据 cookie**（门禁/业务对两种来源零感知）；
  防爆破：同 IP 每分钟最多 5 次尝试；建议救急后即 --rotate 轮换
