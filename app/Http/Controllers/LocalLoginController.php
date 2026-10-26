<?php

namespace App\Http\Controllers;

use App\Services\LocalLoginService;
use App\Services\SsoTicket;
use App\Services\SsoUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SSO 降级本地登录（隐藏应急通道）。
 *
 * 隐藏性：必须访问 /auth/local?local=true 才会渲染登录页（无参数 → 404），
 * 主站界面不出现任何入口/提示；密码由 LocalLoginService 自动生成并持久化。
 * 登录成功后签发与 SSO 相同的 sso_ticket 票据 —— 门禁/业务对两种来源零感知。
 */
class LocalLoginController extends Controller
{
    public function __construct(private LocalLoginService $localLogin) {}

    /**
     * 登录页（?local=true 隐藏激活；无参数视为不存在）。
     */
    public function show(Request $request)
    {
        if ($request->query('local') !== 'true') {
            abort(404);
        }

        // 首次进入即确保存在密码（幂等，只生成一次）
        $this->localLogin->ensurePassword();

        return view('auth.local-login', [
            'error' => $request->query('error') !== null,
            'action' => SsoUrl::to('/auth/local'),
        ]);
    }

    /**
     * 校验密码并签发票据。
     */
    public function login(Request $request)
    {
        // 防爆破：同 IP 每分钟 5 次（公网环境）
        $key = 'sso-local:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect(SsoUrl::to('/auth/local?local=true&error=1'))
                ->withHeaders(['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $password = (string) $request->input('password', '');
        if ($password === '' || ! $this->localLogin->verify($password)) {
            return redirect(SsoUrl::to('/auth/local?local=true&error=1'));
        }

        RateLimiter::clear($key);

        // 与 SSO 同款票据：EnsureSsoAuthenticated 一视同仁
        $ticket = SsoTicket::make(
            ['sub' => 'local', 'name' => 'local', 'email' => ''],
            (int) config('sso.ticket_ttl_hours', 24)
        );

        return redirect()->intended(SsoUrl::to('/'))
            ->cookie(SsoTicket::COOKIE, $ticket, (int) config('sso.ticket_ttl_hours', 24) * 60,
                '/', null, true, false, false, 'Lax');
    }
}
