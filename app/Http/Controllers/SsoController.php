<?php

namespace App\Http\Controllers;

use App\Services\SsoTicket;
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
    /** 生成 authentik 各端点地址（按 issuer 派生，authentik OpenID 约定） */
    private function endpoint(string $suffix): string
    {
        return (string) config('sso.issuer').$suffix;
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

        $authorizeUrl = $this->endpoint('authorize/').'?'.http_build_query([
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

        $tokenResponse = Http::asForm()->post($this->endpoint('token/'), [
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
            ->get($this->endpoint('userinfo/'));
        $user = $userResponse->json();
        if ($userResponse->failed() || empty($user['sub'])) {
            abort(502, 'SSO 获取用户信息失败');
        }

        $request->session()->forget(['sso_state', 'sso_nonce']);

        $ticket = SsoTicket::make($user, (int) config('sso.ticket_ttl_hours', 24));

        return redirect()->intended('/')
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
        $endSession = $this->endpoint('end_session/').'?'.http_build_query(array_filter([
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
