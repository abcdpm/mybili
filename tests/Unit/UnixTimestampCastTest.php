<?php

namespace Tests\Unit;

use App\Casts\UnixTimestamp;
use App\Models\Video;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 时间字段 UnixTimestamp cast 单元测试：
 * 约定「DB 存 'Y-m-d H:i:s'，对外 Unix 秒级时间戳」，与旧版访问器行为保持一致。
 */
class UnixTimestampCastTest extends TestCase
{
    public function test_read_datetime_string_as_timestamp(): void
    {
        $video = new Video();
        $video->setRawAttributes(['pubtime' => '2025-06-14 12:00:00']);

        $this->assertSame(strtotime('2025-06-14 12:00:00'), $video->pubtime);
    }

    public function test_read_null_stays_null(): void
    {
        $video = new Video();
        $video->setRawAttributes(['pubtime' => null]);

        $this->assertNull($video->pubtime);
    }

    public function test_write_timestamp_int_converts_to_datetime_string(): void
    {
        $video = new Video();
        $video->pubtime = 1749883200;

        $this->assertSame(date('Y-m-d H:i:s', 1749883200), $video->getAttributes()['pubtime']);
    }

    public function test_write_datetime_string_passes_through(): void
    {
        $video = new Video();
        $video->fav_time = '2025-01-02 03:04:05';

        $this->assertSame('2025-01-02 03:04:05', $video->getAttributes()['fav_time']);
    }

    public function test_write_carbon_converts_to_datetime_string(): void
    {
        $video = new Video();
        $video->pubtime = Carbon::parse('2025-06-14 12:00:00');

        $this->assertSame('2025-06-14 12:00:00', $video->getAttributes()['pubtime']);
    }

    public function test_write_null_stays_null(): void
    {
        $video = new Video();
        $video->fav_time = null;

        $this->assertNull($video->getAttributes()['fav_time']);
    }

    public function test_cast_set_is_idempotent(): void
    {
        // 老写入路径传入 'Y-m-d H:i:s' 字符串，经过 cast 后必须原样落库（兼容 upsert/fill）
        $cast = new UnixTimestamp();
        $out = $cast->set(new Video(), 'pubtime', '2025-06-14 12:00:00', []);

        $this->assertSame('2025-06-14 12:00:00', $out);
    }
}