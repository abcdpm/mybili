<?php

namespace App\Services\VideoManager\Actions\Video;

use App\Models\Comment;
use App\Models\Video;
use App\Services\BilibiliService;
use App\Services\CommentImageService; // [新增]
use Illuminate\Support\Facades\Log;

class DownloadCommentsAction
{
    public function __construct(
        protected BilibiliService $bilibiliService,
        protected CommentImageService $imageService // [新增]
    ) {}

    public function execute(Video $video, ?int $customLimit = null): void
    {
        // [新增] 增加随机延时，平滑 API 请求峰值
        // 建议休眠 2-5 秒，根据你的队列并发数调整。并发越高，这里需要睡得越久。
        sleep(rand(2, 5));

        Log::info("开始下载评论: {$video->title}");

        $videoInfo = $this->bilibiliService->getVideoInfo($video->bvid);
        if (!$videoInfo) {
            return;
        }
        $aid = $videoInfo['aid'];


        // [新增] 优先使用自定义数量
        if ($customLimit !== null) {
            $targetCount = $customLimit;
             Log::info("使用自定义评论数量: {$targetCount}");
        } else {
            // -------------------------------------------------------------
            // 需求 2: 自适应数量 (数学模型)
            // -------------------------------------------------------------
            // 模型逻辑：
            // 基础 20 条
            // 播放量加成：每 log10(播放量) * 2，上限 20
            // 评论数加成：每 log10(评论数) * 3，上限 20
            // 总上限：60
            $viewCount = $videoInfo['stat']['view'] ?? 0;
            $replyCount = $videoInfo['stat']['reply'] ?? 0;

            $baseCount = 20;
            
            $viewScore = $viewCount > 0 ? min(20, floor(log10($viewCount) * 2)) : 0;
            $replyScore = $replyCount > 0 ? min(20, floor(log10($replyCount) * 3)) : 0;
            
            $targetCount = min(60, $baseCount + $viewScore + $replyScore);
            
            Log::info("自适应评论数量: {$targetCount} (View: {$viewCount}, Reply: {$replyCount})");
        }
        // 获取评论数据
        $commentsData = $this->bilibiliService->getVideoComments($aid, $targetCount);

        if (empty($commentsData)) {
            return;
        }

        // -------------------------------------------------------------
        // 需求 3: 图片本地化处理
        // -------------------------------------------------------------
        foreach ($commentsData as &$data) {
            // 1. 处理表情包
            if (!empty($data['emotes'])) {
                $data['emotes'] = $this->imageService->processEmotes($data['emotes']);
            }
            
            // 2. 处理评论配图
            if (!empty($data['pictures'])) {
                $data['pictures'] = $this->imageService->processPictures($data['pictures']);
            }

            // 写入主评论
            Comment::updateOrCreate(
                ['rpid' => $data['rpid'], 'video_id' => $video->id],
                $data
            );
        }
        unset($data); // 解除引用

        Log::info("评论下载完成: {$video->title}");
    }
}