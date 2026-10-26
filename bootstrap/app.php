<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 子路径前缀（SSO_BASE_PATH/SSO_REDIRECT_URI 推导）必须最先应用：
        // 后续中间件/路由/视图生成的资源 URL 才能带上前缀（base 为空时零影响）
        $middleware->api([
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\ApplyBasePathMiddleware::class,
        ]);
        $middleware->web([
            \App\Http\Middleware\ApplyBasePathMiddleware::class,
            \App\Http\Middleware\CheckDatabaseIsOkMiddleware::class,
            \App\Http\Middleware\CheckCanUpgradedFromRedisMiddleware::class,
        ]);

        // ===== SSO 单点登录（authentik OIDC，SSO_ENABLED=true 时生效）=====
        // web + api 双组都挂门禁；票据 cookie 排除加密以便两组读到同一明文
        // （api 组无 EncryptCookies/StartSession，票据设计为无 session 依赖）
        $middleware->encryptCookies(except: ['sso_ticket']);
        $middleware->web(append: [\App\Http\Middleware\EnsureSsoAuthenticated::class]);
        $middleware->api(append: [\App\Http\Middleware\EnsureSsoAuthenticated::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
