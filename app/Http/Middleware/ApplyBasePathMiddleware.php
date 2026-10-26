<?php

namespace App\Http\Middleware;

use App\Services\SsoUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * 子路径部署（nginx 剥前缀，如 /mybili/ → 容器根路径）时，为服务端生成的
 * 浏览器侧 URL 统一补上子路径前缀（第 SSO 项·资源 URL 修复）。
 *
 * 背景（2026-10-26 线上白屏根因）：@vite / asset() 生成的资源地址是根相对
 * 路径（/build/assets/xxx.css），nginx 剥前缀部署下浏览器会解析到域名根
 * 而 404 → CSS/JS 全挂 → 白屏。Storage::url()（封面/视频）同理依赖 APP_URL
 * 的内外网一致性，改为根相对前缀后由浏览器按当前域解析，最稳。
 *
 * 设计红线（兼容旧版本向上自动升级）：
 *  - base_path 为空（未开 SSO / 根路径部署）→ 直接放行，零行为变化；
 *    注意 SsoUrl::base() 在 SSO 关闭时强制返回 ''（compose 残留
 *    SSO_REDIRECT_URI 不得影响内网根路径直连，2026-10-26 二次白屏回归）；
 *  - 显式配置过 ASSET_URL（CDN 等）→ 不覆盖（asset_url 优先）；
 *  - 仅改内存 config 与 UrlGenerator 状态，不触碰磁盘/数据库；
 *  - 幂等，可在 web + api 双组每请求安全执行（Octane 常驻 worker 无残留问题：
 *    base 来自静态 env 配置，同一进程内恒定）。
 */
class ApplyBasePathMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = SsoUrl::base();

        if ($base !== '') {
            // asset() / @vite：根相对输出 /mybili/build/assets/xxx.css 等。
            // 用户显式配置 ASSET_URL 时以其为准（不覆盖外部 CDN 场景）。
            if (! config('app.asset_url')) {
                config(['app.asset_url' => $base]);
                URL::useAssetOrigin($base);
            }

            // Storage::url()（封面/视频/音频/表情等）：改为根相对前缀路径，
            // 浏览器按当前域 + 子路径解析，不依赖 APP_URL 内外网一致性。
            // 注意：Storage::url() 走默认盘（local，FILESYSTEM_DISK 默认值），
            // 代码里同时存在 public 盘调用 —— 两个盘都要覆盖。
            config([
                'filesystems.disks.local.url' => $base.'/storage',
                'filesystems.disks.public.url' => $base.'/storage',
            ]);
            // FilesystemManager 缓存已解析的 disk 实例，清掉保证本次请求立即生效
            // （Octane 下 config 改动随请求沙箱丢弃，无跨请求残留）
            \Illuminate\Support\Facades\Storage::forgetDisk(['local', 'public']);
        }

        return $next($request);
    }
}
