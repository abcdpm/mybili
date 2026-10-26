<?php

namespace Tests\Feature;

use App\Services\LocalLoginService;
use App\Services\SsoTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * SSO 降级本地登录（隐藏应急通道）测试。
 *
 * 覆盖：无激活参数 404（隐藏性）、?local=true 渲染登录页并生成持久化密码、
 * 密码稳定不重复生成、错误密码拒绝、正确密码签发与 SSO 同款票据、
 * 票据过门禁、防爆破限流、轮换命令。
 */
class SsoLocalLoginTest extends TestCase
{
    use RefreshDatabase;

    private string $passwordFile;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // web 组中间件的 Redis 依赖假客户端（同 SsoAuthTest）
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

        $this->passwordFile = sys_get_temp_dir().'/sso-local-pw-'.uniqid().'.txt';
        config([
            'sso.enabled' => true,
            'sso.client_id' => 'cid',
            'sso.local_password_file' => $this->passwordFile,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->passwordFile);
        parent::tearDown();
    }

    public function test_hidden_without_activation_param(): void
    {
        // 无 ?local=true 视为不存在（隐藏激活、页面不提示）
        $this->get('/auth/local')->assertStatus(404);
    }

    public function test_short_urls_at_root_render_local_login(): void
    {
        // 短链兼容：/ 与 /?local=true 都能激活隐藏登录页（nginx 剥前缀后即
        // https://paccu.cn/mybili/?local=true 与 https://paccu.cn/mybili?local=true）
        $password = app(LocalLoginService::class)->ensurePassword();
        $this->assertNotSame('', $password);

        $this->get('/?local=true')->assertStatus(200);
    }

    public function test_short_url_param_does_not_bypass_gate_elsewhere(): void
    {
        // 安全边界：?local=true 仅在根路径放行；API 与其它路径携带同参数不得绕过 SSO
        $this->getJson('/api/videos?local=true')
            ->assertStatus(401)
            ->assertJsonPath('sso_redirect', '/auth/sso/redirect');

        $this->get('/fav/123?local=true')->assertRedirect('/auth/sso/redirect');

        // 根路径不带参数仍走 SSO
        $this->get('/')->assertRedirect('/auth/sso/redirect');
    }

    public function test_activation_param_renders_login_and_generates_password(): void
    {
        $this->assertFileDoesNotExist($this->passwordFile);
        $this->get('/auth/local?local=true')->assertStatus(200);
        $this->assertFileExists($this->passwordFile, '首次进入应生成持久化密码');
        $this->assertNotSame('', trim((string) file_get_contents($this->passwordFile)));
    }

    public function test_password_is_stable_across_visits(): void
    {
        $svc = app(LocalLoginService::class);
        $first = $svc->ensurePassword();
        $second = $svc->ensurePassword();
        $this->assertSame($first, $second, '密码应稳定复用，不得每次生成');
    }

    public function test_wrong_password_is_rejected_without_ticket(): void
    {
        $this->post('/auth/local', ['password' => 'wrong-password'])
            ->assertRedirect('/auth/local?local=true&error=1');

        $this->assertFalse(
            collect(request()->headers->get('Set-Cookie') ?? [])->count() > 0
        );
    }

    public function test_correct_password_issues_sso_ticket_and_passes_gate(): void
    {
        $password = app(LocalLoginService::class)->ensurePassword();

        $resp = $this->post('/auth/local', ['password' => $password]);
        $resp->assertStatus(302);

        $cookie = collect($resp->headers->getCookies())->first(fn ($c) => $c->getName() === SsoTicket::COOKIE);
        $this->assertNotNull($cookie, '本地登录应签发与 SSO 相同的票据');
        $user = SsoTicket::verify($cookie->getValue());
        $this->assertSame('local', $user['sub'] ?? null);

        // 票据过门禁：携带票据访问页面不再跳登录
        $this->withUnencryptedCookie(SsoTicket::COOKIE, $cookie->getValue())
            ->get('/')->assertStatus(200);
    }

    public function test_brute_force_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/auth/local', ['password' => 'bad-'.$i])->assertRedirect('/auth/local?local=true&error=1');
        }
        $resp = $this->post('/auth/local', ['password' => 'bad-6']);
        $this->assertTrue($resp->headers->has('Retry-After'), '连续错误应触发限流');
    }

    public function test_rotate_command_changes_password(): void
    {
        $svc = app(LocalLoginService::class);
        $before = $svc->ensurePassword();

        $this->artisan('app:sso-local-password', ['--rotate' => true])->assertExitCode(0);

        $this->assertNotSame($before, $svc->ensurePassword(), '轮换后密码应变化');
    }

    public function test_local_login_works_even_when_sso_disabled(): void
    {
        // 应急通道与 SSO 开关解耦：即便 SSO 配置被误关，本地登录依旧可用
        config(['sso.enabled' => false]);
        $password = app(LocalLoginService::class)->ensurePassword();
        $resp = $this->post('/auth/local', ['password' => $password]);
        $resp->assertStatus(302);
    }

    /** 子路径部署：登录表单 action 与错误回跳都带 /mybili 前缀 */
    public function test_subpath_prefix_in_form_action_and_error_redirect(): void
    {
        config(['sso.base_path' => '/mybili']);

        $this->get('/auth/local?local=true')
            ->assertStatus(200)
            ->assertSee('action="/mybili/auth/local"', false);

        $this->post('/auth/local', ['password' => 'wrong'])
            ->assertRedirect('/mybili/auth/local?local=true&error=1');
    }

    /** 子路径部署：登录成功回到 intended（带前缀），而不是裸根路径 */
    public function test_intended_redirect_keeps_subpath_prefix(): void
    {
        config(['sso.base_path' => '/mybili']);

        // 未登录访问子路径页面 → 记录 intended（带前缀）
        $this->get('/fav/123')->assertRedirect('/mybili/auth/sso/redirect');

        $password = app(LocalLoginService::class)->ensurePassword();
        $resp = $this->post('/auth/local', ['password' => $password]);
        $this->assertSame(
            'http://localhost/mybili/fav/123',
            (string) $resp->headers->get('Location'),
            '登录成功应回到带前缀的原目标页'
        );
    }
}
