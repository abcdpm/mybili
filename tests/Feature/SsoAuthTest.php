<?php

namespace Tests\Feature;

use App\Services\SsoTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * SSO 单点登录（authentik OIDC）测试。
 *
 * 覆盖：默认关闭零影响（兼容性）、开启后页面 302 / API 401、
 * 授权跳转参数、回调 state 校验、回调成功签发票据、票据验证与登出。
 */
class SsoAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 防悬挂：未命中 Http::fake 映射的请求立即报错（而不是真实外联 paccu.cn 无限等待）。
        // 注意：不要在这里注册 '*' 兜底 stub —— 先注册先匹配，会吞掉用例内更精确的映射
        Http::preventStrayRequests();

        // web 组的 CheckCanUpgradedFromRedis 中间件会触达 Redis（生产才有）；
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

    private function enableSso(): void
    {
        config([
            'sso.enabled' => true,
            'sso.issuer' => 'https://paccu.cn/authentik/application/o/mybili/',
            'sso.client_id' => 'test-client-id',
            'sso.client_secret' => 'test-client-secret',
            'sso.redirect_uri' => 'https://paccu.cn/mybili/auth/sso/callback',
            'sso.post_logout_redirect_uri' => 'https://paccu.cn/mybili/',
        ]);
    }

    public function test_disabled_by_default_has_zero_impact(): void
    {
        // 未配置 SSO 时页面照常（向后兼容，兼容旧版本向上自动升级）
        $this->get('/')->assertStatus(200);
    }

    public function test_guest_page_redirects_to_sso(): void
    {
        $this->enableSso();
        $this->get('/')->assertRedirect('/auth/sso/redirect');
    }

    public function test_guest_api_returns_401_with_redirect(): void
    {
        $this->enableSso();
        $this->getJson('/api/videos')
            ->assertStatus(401)
            ->assertJsonPath('sso_redirect', '/auth/sso/redirect');
    }

    public function test_redirect_builds_authorize_url_with_required_params(): void
    {
        $this->enableSso();
        $resp = $this->get('/auth/sso/redirect');
        $resp->assertStatus(302);
        $loc = (string) $resp->headers->get('Location');

        $this->assertStringStartsWith('https://paccu.cn/authentik/application/o/mybili/authorize/?', $loc);
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('test-client-id', $q['client_id'] ?? null);
        $this->assertSame('https://paccu.cn/mybili/auth/sso/callback', $q['redirect_uri'] ?? null);
        $this->assertSame('code', $q['response_type'] ?? null);
        $this->assertNotEmpty($q['state'] ?? null);
        $this->assertNotEmpty($q['nonce'] ?? null);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $this->enableSso();
        $this->withSession(['sso_state' => 'expected-state']);
        $this->get('/auth/sso/callback?code=abc&state=wrong-state')->assertStatus(400);
    }

    public function test_callback_exchanges_code_and_sets_ticket_cookie(): void
    {
        $this->enableSso();
        Http::fake([
            '*/token/*' => Http::response(['access_token' => 'at-123', 'id_token' => 'x.y.z'], 200),
            '*/userinfo/*' => Http::response(['sub' => 'user-1', 'name' => '测试用户', 'email' => 'me@example.com'], 200),
            '*' => Http::response([], 200),
        ]);

        $resp = $this->withSession(['sso_state' => 'good-state'])
            ->get('/auth/sso/callback?code=abc&state=good-state');

        $resp->assertStatus(302);
        $cookie = collect($resp->headers->getCookies())->first(fn ($c) => $c->getName() === SsoTicket::COOKIE);
        $this->assertNotNull($cookie, '回调应签发 sso_ticket cookie');

        // 票据可校验出用户身份与有效期
        $user = SsoTicket::verify($cookie->getValue());
        $this->assertNotNull($user);
        $this->assertSame('user-1', $user['sub']);
        $this->assertSame('测试用户', $user['name']);
    }

    public function test_ticket_passes_middleware_and_forgery_rejected(): void
    {
        $this->enableSso();
        $good = SsoTicket::make(['sub' => 'u1', 'name' => 'N'], 1);
        $this->withUnencryptedCookie(SsoTicket::COOKIE, $good)
            ->get('/')->assertStatus(200);

        // 伪造签名应被拒（回到登录跳转）
        $this->withUnencryptedCookie(SsoTicket::COOKIE, 'forged.payload.signature')
            ->get('/')->assertRedirect('/auth/sso/redirect');
    }

    public function test_logout_clears_ticket_and_redirects_to_end_session(): void
    {
        $this->enableSso();
        $resp = $this->get('/auth/sso/logout');
        $resp->assertStatus(302);
        $loc = (string) $resp->headers->get('Location');
        $this->assertStringStartsWith('https://paccu.cn/authentik/application/o/mybili/end_session/?', $loc);
        $cookie = collect($resp->headers->getCookies())->first(fn ($c) => $c->getName() === SsoTicket::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->getExpiresTime() < time(), '登出应过期票据 cookie');
    }
}
