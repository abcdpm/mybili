<?php

namespace Tests\Unit;

use App\Services\DanmakuConverterService;
use PHPUnit\Framework\TestCase;

/**
 * 弹幕转换服务单元测试（纯逻辑，无框架依赖）。
 */
class DanmakuConverterServiceTest extends TestCase
{
    protected DanmakuConverterService $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new DanmakuConverterService();
    }

    public function test_convert_rolling_danmaku(): void
    {
        $result = $this->converter->convert([
            ['id' => 1, 'progress' => 45811, 'mode' => 1, 'color' => 16777215, 'content' => '这是一条滚动弹幕'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('这是一条滚动弹幕', $result[0]['text']);
        $this->assertSame(45.811, $result[0]['time']);
        $this->assertSame(0, $result[0]['mode']);
        $this->assertSame('#FFFFFF', $result[0]['color']);
        $this->assertFalse($result[0]['border']);
    }

    public function test_convert_top_and_bottom_danmaku(): void
    {
        $result = $this->converter->convert([
            ['id' => 1, 'progress' => 1000, 'mode' => 5, 'color' => 16777215, 'content' => '顶部'],
            ['id' => 2, 'progress' => 2000, 'mode' => 4, 'color' => 16777215, 'content' => '底部'],
        ]);

        $this->assertSame(1, $result[0]['mode'], 'mode 5 应转换为顶部弹幕');
        $this->assertSame(2, $result[1]['mode'], 'mode 4 应转换为底部弹幕');
    }

    public function test_convert_color(): void
    {
        $result = $this->converter->convert([
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '红色'],
        ]);

        $this->assertSame('#0000FF', $result[0]['color'], '十进制 255 应转换为 #0000FF');
    }

    public function test_downgrade_advanced_danmaku(): void
    {
        // 高级弹幕内容为 JSON 数组，索引 4 为文本
        $advanced = json_encode([0, 1, 2, 3, '高级弹幕文本', 5], JSON_UNESCAPED_UNICODE);
        $result = $this->converter->convert([
            ['id' => 1, 'progress' => 0, 'mode' => 7, 'color' => 16777215, 'content' => $advanced],
        ]);

        $this->assertSame('高级弹幕文本', $result[0]['text']);
        $this->assertSame(0, $result[0]['mode'], '高级弹幕应降级为滚动');
    }

    public function test_normal_content_kept_as_is(): void
    {
        $result = $this->converter->convert([
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 16777215, 'content' => '{"not": "array"}'],
        ]);

        $this->assertSame('{"not": "array"}', $result[0]['text']);
    }
}