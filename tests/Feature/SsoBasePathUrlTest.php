<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyBasePathMiddleware;
use App\Models\Cover;
use App\Models\VideoPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 子路径部署资源 URL 前缀测试（第 SSO 项·白屏根因修复回归）。
 *
 * 背景（2026-10-26 线上实测）：nginx 剥前缀部署（/mybili/ → 容器根路径）下，
 * @vite/asset()/Storage::url() 生成的根相对或 APP_URL 绝对地址在浏览器侧
 * 丢失 /mybili 前缀 → CSS/JS 404 → 白屏。
 *
 * 覆盖：
 *  - 根部署（base 为空）零行为变化（兼容旧版本向上自动升级）；
 *  - 子路径下 asset()/Storage::url() 带前缀、welcome 注入 window.__MYBILI_BASE__；
 *  - 显式配置 ASSET_URL 时不覆盖（CDN 场景）；
 *  - 模型层（封面/分P）输出的浏览器地址带前缀。
 */
class SsoBasePathUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // web 组 CheckCanUpgradedFromRedis 中间件会触达 Redis（生产才有）；
        // 测试用宽松假客户端兜底（所有方法返回 null = 非旧版数据库，正常放行）
        $fake = new class
        {
            public function __call($name, $args)
            {
                return null;
            }
        };
        Redis::shouldReceive('connection')->andReturn(new class($fake)
        {
            public function __construct(private object $fake) {}

            public function client()
            {
                return $this->fake;
            }
        });
    }

    /** 直接执行中间件（不经完整请求管线，聚焦 URL 生成行为） */
    private function runBasePathMiddleware(): void
    {
        (new ApplyBasePathMiddleware())->handle(
            Request::create('/'),
            fn () => response('ok')
        );
    }

    /** 模拟子路径 SSO 部署：开关打开 + 前缀配置齐备 */
    private function enableSubpathSso(): void
    {
        config([
            'sso.enabled' => true,
            'sso.base_path' => '/mybili',
        ]);
    }

    /** 根部署（默认，未配 SSO 子路径）：所有 URL 生成保持旧版行为 */
    public function test_root_deploy_has_zero_impact(): void
    {
        $assetBefore = asset('build/assets/app.css');
        $localUrlBefore = config('filesystems.disks.local.url');
        $publicUrlBefore = config('filesystems.disks.public.url');

        $this->runBasePathMiddleware();

        // asset() 与磁盘 url 配置均不被改动
        $this->assertSame($assetBefore, asset('build/assets/app.css'));
        $this->assertSame($localUrlBefore, config('filesystems.disks.local.url'));
        $this->assertSame($publicUrlBefore, config('filesystems.disks.public.url'));

        // welcome 注入空前缀，前端全部原样透传
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('window.__MYBILI_BASE__ = ""', false);
    }

    /**
     * 本次线上二次白屏回归（2026-10-26）：SSO 已关闭，但 compose 环境残留
     * SSO_REDIRECT_URI → config 仍推导出 base_path=/mybili。
     * 此时用户内网直连根路径 http://192.168.0.145:5151/，
     * 资源 URL 不得带前缀（否则请求 /mybili/build/... 404 → 白屏）。
     */
    public function test_disabled_sso_ignores_stale_base_path(): void
    {
        // SSO 关闭（enabled=false）但 base_path 因残留 REDIRECT_URI 推导为 /mybili
        config([
            'sso.enabled' => false,
            'sso.base_path' => '/mybili',
        ]);

        $this->runBasePathMiddleware();

        // SsoUrl::base() 作为唯一闸门必须返回空
        $this->assertSame('', \App\Services\SsoUrl::base());

        // asset() 不带前缀（内网直连可正常加载 /build/assets/...）
        $this->assertStringNotContainsString(
            '/mybili/',
            asset('build/assets/app.css'),
            'SSO 关闭时资源 URL 不得携带残留的子路径前缀'
        );

        // Storage::url() 同样不带前缀
        $this->assertStringNotContainsString(
            '/mybili/',
            Storage::url('images/cover.webp'),
            'SSO 关闭时 Storage URL 不得携带残留的子路径前缀'
        );

        // welcome 注入空前缀 → 前端拦截器/router/图片全部原样透传
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('window.__MYBILI_BASE__ = ""', false);
    }

    /** SSO 关闭 + 残留 base_path：本地登录表单/回跳也不得带前缀（同一闸门） */
    public function test_disabled_sso_ignores_stale_base_path_on_local_login(): void
    {
        config([
            'sso.enabled' => false,
            'sso.base_path' => '/mybili',
            'sso.local_password_file' => sys_get_temp_dir().'/sso-lp-'.uniqid().'.txt',
        ]);

        $this->get('/auth/local?local=true')
            ->assertStatus(200)
            ->assertSee('action="/auth/local"', false)
            ->assertDontSee('action="/mybili/auth/local"', false);
    }

    /** 子路径部署：asset() 输出带前缀（@vite 走同一 asset() 解析 → 修复白屏） */
    public function test_subpath_prefixes_asset_urls(): void
    {
        $this->enableSubpathSso();
        $this->runBasePathMiddleware();

        $this->assertSame('/mybili/build/assets/app.css', asset('build/assets/app.css'));
        $this->assertSame('/mybili/assets/images/favicon.svg', asset('assets/images/favicon.svg'));
    }

    /** 子路径部署：Storage::url()（封面/视频/表情等）输出带前缀的根相对地址 */
    public function test_subpath_prefixes_storage_urls(): void
    {
        $this->enableSubpathSso();
        $this->runBasePathMiddleware();

        $this->assertSame(
            '/mybili/storage/images/cover.webp',
            Storage::url('images/cover.webp'),
            'public 盘 URL 应带子路径前缀（浏览器按当前域解析，不依赖 APP_URL 内外网一致性）'
        );
    }

    /** 显式配置 ASSET_URL（CDN 等）时不得覆盖 */
    public function test_explicit_asset_url_is_not_overridden(): void
    {
        $this->enableSubpathSso();
        // 模拟用户已通过 ASSET_URL env 显式配置（cdn 场景）：中间件不得改动 asset 生成
        config(['app.asset_url' => 'https://cdn.example.com']);
        \Illuminate\Support\Facades\URL::useAssetOrigin('https://cdn.example.com');
        $cdnBefore = asset('build/assets/app.css');

        $this->runBasePathMiddleware();

        $this->assertSame(
            $cdnBefore,
            asset('build/assets/app.css'),
            '用户显式配置的 ASSET_URL（如 CDN）不应被子路径前缀覆盖'
        );
        $this->assertStringStartsWith('https://cdn.example.com/', $cdnBefore);
    }

    /** welcome 注入子路径前缀全局变量（前端 withBase/拦截器的数据源） */
    public function test_welcome_injects_base_path_global(): void
    {
        $this->enableSubpathSso();

        // SSO 已开启 → 携带有效票据过门禁后再断言页面内容
        $ticket = \App\Services\SsoTicket::make(['sub' => 'u1', 'name' => 'N'], 1);
        $this->withUnencryptedCookie(\App\Services\SsoTicket::COOKIE, $ticket)
            ->get('/')
            ->assertStatus(200)
            ->assertSee('window.__MYBILI_BASE__ = "/mybili"', false)
            // favicon（asset() 输出）同步带前缀
            ->assertSee('/mybili/assets/images/favicon.svg', false);
    }

    /** 模型层：封面与分P输出给前端的浏览器地址带前缀（播放器/列表直用） */
    public function test_model_accessors_emit_prefixed_browser_urls(): void
    {
        $this->enableSubpathSso();
        $this->runBasePathMiddleware();

        $cover = new Cover();
        $cover->path = 'images/abc.webp';
        $this->assertSame('/mybili/storage/images/abc.webp', $cover->image_url);

        $part = new VideoPart();
        $part->video_download_path = 'videos/abc.mp4';
        $this->assertSame('/mybili/storage/videos/abc.mp4', $part->video_download_url);
    }

    /** 中间件幂等：重复执行不产生双前缀（Octane 常驻 worker 多请求安全） */
    public function test_middleware_is_idempotent(): void
    {
        $this->enableSubpathSso();
        $this->runBasePathMiddleware();
        $this->runBasePathMiddleware();

        $this->assertSame('/mybili/storage/images/a.webp', Storage::url('images/a.webp'));
        $this->assertSame('/mybili/build/assets/app.css', asset('build/assets/app.css'));
    }
}
