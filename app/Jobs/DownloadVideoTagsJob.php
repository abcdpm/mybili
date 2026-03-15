<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\BilibiliService;
use Illuminate\Contracts\Queue\ShouldQueue;

class DownloadVideoTagsJob extends BaseScheduledRateLimitedJob implements ShouldQueue
{
    // 极大值，防止被打回队列超过默认3次后报错失败
    public $tries = 9999;
    public $maxExceptions = 3;

    public function __construct(public Video $video)
    {
        // 默认队列
    }

    protected function getRateLimitKey(): string
    {
        return 'bilibili_api_general';
    }

    protected function process(): void
    {
        if ($this->video->invalid) {
            return; 
        }

        $bilibiliService = app(BilibiliService::class);
        $tags = $bilibiliService->getVideoTags($this->video->bvid);
        
        if (!empty($tags)) {
            $tagData = array_map(function ($tag) {
                return [
                    'tag_id'   => $tag['tag_id'] ?? null,
                    'tag_name' => $tag['tag_name'] ?? '',
                    // 【新增】：记录特殊的标签类型（bgm / topic 等）和跳转链接
                    'tag_type' => $tag['tag_type'] ?? 'ordinary',
                    'jump_url' => $tag['jump_url'] ?? '',
                ];
            }, $tags);

            $this->video->update(['tags' => $tagData]);
        }
    }
}