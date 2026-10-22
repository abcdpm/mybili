<?php
namespace App\Services\VideoManager\Contracts;

use App\Models\Video;

interface DanmakuServiceInterface
{
    public function getVideoDanmakuCount(Video $video): int;
    public function getDanmaku(string $cid): array;

    /**
     * 获取转换后的播放器弹幕（带缓存与内容指纹）。
     *
     * @return array{data: array, etag: string}
     */
    public function getConvertedDanmaku(string $cid): array;
}