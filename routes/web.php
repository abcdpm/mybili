<?php

use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

// ===== SSO 单点登录（authentik OIDC）——仅 SSO_ENABLED=true 时由中间件启用 =====
// 顺序在 SPA catch-all 之前；auth/* 路径被中间件白名单放行
Route::get('/auth/sso/redirect', [SsoController::class, 'redirect']);
Route::get('/auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
Route::get('/auth/sso/logout', [SsoController::class, 'logout']);

// SSO 降级本地登录（隐藏应急通道）：须带 ?local=true 激活，无参数 404，主站零提示
Route::get('/auth/local', [\App\Http\Controllers\LocalLoginController::class, 'show']);
Route::post('/auth/local', [\App\Http\Controllers\LocalLoginController::class, 'login']);

// 短链兼容（隐藏应急入口）：/ 或 /?local=true —— 带 local=true 时渲染本地登录页，
// 其余情况照常渲染 SPA。安全性：门禁仅对"根路径 + local=true"这一种组合放行，
// API/任意路径携带同参数不会绕过 SSO（见 EnsureSsoAuthenticated）
Route::get('/', function (\Illuminate\Http\Request $request) {
    if ($request->query('local') === 'true') {
        return app(\App\Http\Controllers\LocalLoginController::class)->show($request);
    }

    return view('welcome');
});

// 1. 保留原本的 GET 规则
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');

// 2. 新增 POST 规则：捕获后强制发起 302 重定向到对应的 GET 地址
Route::post('/{any}', function ($any) {
    return redirect('/'.$any);
})->where('any', '.*');
