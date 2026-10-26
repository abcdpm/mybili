<?php

namespace App\Http\Middleware;

use App\Services\SsoTicket;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SSO 单点登录门禁（第 SSO 项）。
 *
 * 行为矩阵：
 *  - SSO_ENABLED 未开启：直接放行（向后兼容，零行为变化）；
 *  - /auth/sso/* 路径：放行（登录流程自身）；
 *  - 携带有效 sso_ticket cookie：放行（web 与 api 组通用，票据不依赖 session）；
 *  - 未登录访问页面：302 → /auth/sso/redirect（返回地址记入 intended）；
 *  - 未登录访问 API：401 JSON 附 sso_redirect，由前端/调用方跳转登录。
 */
class EnsureSsoAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sso.enabled')) {
            return $next($request);
        }

        $path = $request->path();
        // auth/* 全放行：SSO 流程端点 + 降级本地登录（应急通道）
        if (str_starts_with($path, 'auth/')) {
            return $next($request);
        }

        if (SsoTicket::verify($request->cookie(SsoTicket::COOKIE))) {
            return $next($request);
        }

        $redirect = '/auth/sso/redirect';
        if ($request->expectsJson() || str_starts_with($path, 'api/')) {
            return response()->json(['message' => 'Unauthenticated.', 'sso_redirect' => $redirect], 401);
        }

        return redirect()->guest($redirect);
    }
}
