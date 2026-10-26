<?php

use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

// ===== SSO 单点登录（authentik OIDC）——仅 SSO_ENABLED=true 时由中间件启用 =====
// 顺序在 SPA catch-all 之前；三个端点被中间件白名单放行
Route::get('/auth/sso/redirect', [SsoController::class, 'redirect']);
Route::get('/auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
Route::get('/auth/sso/logout', [SsoController::class, 'logout']);

// 1. 保留原本的 GET 规则
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');

// 2. 新增 POST 规则：捕获后强制发起 302 重定向到对应的 GET 地址
Route::post('/{any}', function ($any) {
    return redirect('/'.$any);
})->where('any', '.*');
