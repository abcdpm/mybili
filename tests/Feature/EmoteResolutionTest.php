<?php

namespace Tests\Feature;

use App\Models\Emote;
use App\Services\Comment\EmoteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 评论表情包解析（EmoteResolver）测试。
 *
 * 重点覆盖 gif 动图表情的路径派生：
 * 优化器对动图跳过转换、单文件存储（如 md5.gif），旧实现会派生不存在的
 * .webp 侧车路径导致裂图；现按扩展名区分，动图 webp/raw 均指向真实文件。
 */
class EmoteResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_webp_emote_resolves_to_webp_and_raw_pair(): void
    {
        // 普通图双文件：filename 存压缩图 md5.webp
        Emote::create(['text' => '[doge]', 'filename' => 'a1b2c3d4.webp', 'url_hash' => 'h1']);

        $resolver = app(EmoteResolver::class);
        $resolver->prime(['[doge]']);

        $paths = $resolver->resolve('[doge]');
        $this->assertNotNull($paths);
        $this->assertSame('/storage/emotes/a1b2c3d4.webp', $paths['webp']);
        $this->assertSame('/storage/emotes/a1b2c3d4.webp', $paths['raw']);
    }

    public function test_gif_emote_resolves_to_actual_file_not_missing_webp(): void
    {
        // 动图单文件：filename 存 md5.gif（优化器跳过转换）
        Emote::create(['text' => '[哦哦]', 'filename' => 'ff00aa11.gif', 'url_hash' => 'h2']);

        $resolver = app(EmoteResolver::class);
        $resolver->prime(['[哦哦]']);

        $paths = $resolver->resolve('[哦哦]');
        $this->assertNotNull($paths);
        // 关键断言：webp 位必须指向真实存在的 .gif 文件，而不是派生出的 .webp（裂图根因）
        $this->assertSame('/storage/emotes/ff00aa11.gif', $paths['webp']);
        $this->assertSame('/storage/emotes/ff00aa11.gif', $paths['raw']);
    }

    public function test_png_only_emote_resolves_to_actual_file(): void
    {
        // 老缓存/跳过转换的单 png 文件同样不能派生不存在的 .webp
        Emote::create(['text' => '[笑哭]', 'filename' => 'beefcafe.png', 'url_hash' => 'h3']);

        $resolver = app(EmoteResolver::class);
        $resolver->prime(['[笑哭]']);

        $paths = $resolver->resolve('[笑哭]');
        $this->assertSame('/storage/emotes/beefcafe.png', $paths['webp']);
        $this->assertSame('/storage/emotes/beefcafe.png', $paths['raw']);
    }

    public function test_unknown_emote_resolves_to_null(): void
    {
        $resolver = app(EmoteResolver::class);
        $resolver->prime(['[不存在]']);

        $this->assertNull($resolver->resolve('[不存在]'));
    }
}
