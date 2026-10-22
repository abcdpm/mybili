<?php

namespace Tests\Feature;

use App\Enums\SettingKey;
use App\Jobs\TranscodeVideoJob;
use App\Models\VideoPart;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 转码配置（第 19 项）消费测试。
 *
 * 验证 transcode_config 的质量/预设真正作用于 ffmpeg 命令构建，
 * 未配置时回落旧版硬编码值（向后兼容）。
 */
class TranscodeConfigTest extends TestCase
{
    use RefreshDatabase;

    private function buildCommand(TranscodeVideoJob $job, string $source = '/tmp/a.mp4', string $target = '/tmp/b.mp4'): string
    {
        $m = new ReflectionMethod(TranscodeVideoJob::class, 'buildCommand');
        $m->setAccessible(true);

        return $m->invoke($job, $source, $target);
    }

    public function test_defaults_match_legacy_hardcoded_command(): void
    {
        $job = new TranscodeVideoJob(new VideoPart, 'cpu');
        $cmd = $this->buildCommand($job);

        $this->assertStringContainsString('-c:v libx264', $cmd);
        $this->assertStringContainsString('-crf 26', $cmd);
        $this->assertStringContainsString('-preset veryfast', $cmd);
    }

    public function test_config_quality_and_preset_are_applied(): void
    {
        app(SettingsService::class)->put(SettingKey::TRANSCODE_CONFIG->value, [
            'hwaccel' => 'cpu', 'quality' => 24, 'preset' => 'fast',
        ]);

        $job = new TranscodeVideoJob(new VideoPart, 'cpu');
        $cmd = $this->buildCommand($job);

        $this->assertStringContainsString('-crf 24', $cmd);
        $this->assertStringContainsString('-preset fast', $cmd);
    }

    public function test_nvenc_rejects_invalid_preset_and_falls_back_to_p4(): void
    {
        app(SettingsService::class)->put(SettingKey::TRANSCODE_CONFIG->value, [
            'hwaccel' => 'cuda', 'quality' => 23, 'preset' => 'veryfast', // x264 预设对 nvenc 无效
        ]);

        $job = new TranscodeVideoJob(new VideoPart, 'nvenc');
        $cmd = $this->buildCommand($job);

        $this->assertStringContainsString('-cq 23', $cmd);
        $this->assertStringContainsString('-preset p4', $cmd);
        $this->assertStringNotContainsString('-preset veryfast', $cmd);
    }

    public function test_nvenc_accepts_p_level_preset(): void
    {
        app(SettingsService::class)->put(SettingKey::TRANSCODE_CONFIG->value, [
            'hwaccel' => 'cuda', 'quality' => 26, 'preset' => 'p2',
        ]);

        $job = new TranscodeVideoJob(new VideoPart, 'nvenc');
        $cmd = $this->buildCommand($job);

        $this->assertStringContainsString('-preset p2', $cmd);
    }
}
