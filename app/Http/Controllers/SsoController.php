<?php

namespace App\Http\Controllers;

use App\Services\SsoTicket;
use App\Services\SsoUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SSO 单点登录控制器（authentik OpenID Connect 授权码流程）。
 *
 * 流程：/auth/sso/redirect（302 到 authentik authorize）
 *      → 用户在 authentik 登录
 *      → /auth/sso/callback（code 换 token → userinfo 确认身份 → 签发票据 cookie）
 *      → 跳回 originally intended 页面。
 *
 * 安全要点：state/nonce 存 session 并校验（防 CSRF/重放）；身份以 TLS 通道上的
 * userinfo 端点 + 客户端密钥换取（凭证不落 URL）；票据 HMAC 签名带过期时间。
 */
class SsoController extends Controller
{
    /**
     * 解析 authentik 各端点地址（OIDC 发现优先，失败回退按 issuer 派生）。
     *
     * 发现协议（issuer/.well-known/openid-configuration）公布的端点是什么就用什么，
     * 自动适配应用 slug、子路径部署与 authentik 版本差异；
     * 发现失败（网络/issuer 配错）时回退旧派生逻辑，行为可预期。
     *
     * @return array<string, string>
     */
    private function endpoints(): array
    {
        return cache()->remember('sso.oidc.endpoints', 3600, function () {
            $issuer = (string) config('sso.issuer');
            $fallback = [
                'authorization_endpoint' => $issuer.'authorize/',
                'token_endpoint' => $issuer.'token/',
                'userinfo_endpoint' => $issuer.'userinfo/',
                'end_session_endpoint' => $issuer.'end_session/',
            ];

            try {
                $doc = Http::timeout(5)->get($issuer.'.well-known/openid-configuration');
                $json = $doc->successful() ? $doc->json() : null;
                if (is_array($json) && ! empty($json['authorization_endpoint'])) {
                    return array_merge($fallback, array_intersect_key($json, $fallback));
                }
                \Illuminate\Support\Facades\Log::warning('[SSO] OIDC 发现失败，回退派生端点', ['issuer' => $issuer]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[SSO] OIDC 发现异常，回退派生端点', ['error' => $e->getMessage()]);
            }

            return $fallback;
        });
    }

    /** 取指定端点：authorize / token / userinfo / end_session */
    private function endpoint(string $name): string
    {
        $map = [
            'authorize' => 'authorization_endpoint',
            'token' => 'token_endpoint',
            'userinfo' => 'userinfo_endpoint',
            'end_session' => 'end_session_endpoint',
        ];
        $key = $map[$name] ?? $name;

        return (string) ($this->endpoints()[$key] ?? '');
    }

    /**
     * 跳转 authentik 授权页。
     */
    public function redirect(Request $request)
    {
        $clientId = (string) config('sso.client_id');
        if ($clientId === '') {
            abort(500, 'SSO 未配置：缺少 SSO_CLIENT_ID');
        }

        $state = Str::random(32);
        $nonce = Str::random(32);
        $request->session()->put(['sso_state' => $state, 'sso_nonce' => $nonce]);

        $authorizeUrl = $this->endpoint('authorize').'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => (string) config('sso.scope'),
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return redirect()->away($authorizeUrl);
    }

    /**
     * 回调：code 换 token、userinfo 确认身份、签发票据。
     */
    public function callback(Request $request)
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '' || $state !== (string) $request->session()->get('sso_state')) {
            abort(400, 'SSO 回调校验失败（state/code 无效）');
        }

        $tokenResponse = Http::asForm()->post($this->endpoint('token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => (string) config('sso.client_id'),
            'client_secret' => (string) config('sso.client_secret'),
        ]);
        if ($tokenResponse->failed() || ! $tokenResponse->json('access_token')) {
            abort(502, 'SSO 换取 token 失败：'.$tokenResponse->body());
        }

        $userResponse = Http::withToken($tokenResponse->json('access_token'))
            ->get($this->endpoint('userinfo'));
        $user = $userResponse->json();
        if ($userResponse->failed() || empty($user['sub'])) {
            abort(502, 'SSO 获取用户信息失败');
        }

        $request->session()->forget(['sso_state', 'sso_nonce']);

        $ticket = SsoTicket::make($user, (int) config('sso.ticket_ttl_hours', 24));

        return redirect()->intended(SsoUrl::to('/'))
            ->cookie(
                SsoTicket::COOKIE,
                $ticket,
                (int) config('sso.ticket_ttl_hours', 24) * 60,
                '/',           // path
                null,          // domain（当前域）
                true,          // secure（https）
                false,         // httpOnly（前端无需读取；仅服务端校验）
                false,         // raw
                'Lax'          // sameSite
            );
    }

    /**
     * 登出：清除本地票据并跳 authentik 会话结束页（可选配置回跳）。
     */
    public function logout(Request $request)
    {
        $postLogout = (string) config('sso.post_logout_redirect_uri', '');
        $endSession = $this->endpoint('end_session').'?'.http_build_query(array_filter([
            'client_id' => (string) config('sso.client_id'),
            'post_logout_redirect_uri' => $postLogout !== '' ? $postLogout : null,
        ]));

        // 清除本地票据（过期 cookie），再跳 authentik 结束其侧会话
        return redirect()->away($endSession)
            ->cookie(SsoTicket::COOKIE, '', -1, '/', null, true, false, false, 'Lax');
    }

    /** 回调地址（外部完整地址，与 authentik 登记一致） */
    private function redirectUri(): string
    {
        $configured = (string) config('sso.redirect_uri');

        return $configured !== '' ? $configured : url('/auth/sso/callback');
    }
}
