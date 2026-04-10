<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\BilibiliService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadVideoTagsJob implements ShouldQueue
{
    // 【修改】引入 Laravel 默认的队列 Traits
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $maxExceptions = 3;

    public function __construct(public Video $video)
    {
        // 默认队列
        $this->onQueue('default');
    }

    public function handle(): void
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