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

    // [修改] 接收 sleep 参数，默认值为 3
    public function execute(Video $video, ?int $customLimit = null, int $sleep = 3): void
    {
        // [新增] 增加随机延时，平滑 API 请求峰值
        // 建议休眠 2-5 秒，根据你的队列并发数调整。并发越高，这里需要睡得越久。
        // sleep(rand(2, 5));
        // [修改] 使用传入的休眠时间
        sleep($sleep);

        // [修改] 统一日志格式：固定消息 + ID上下文
        Log::info('Start downloading comments', [
            'video_id' => $video->id,
            'title'    => $video->title,
            'bvid'     => $video->bvid,
            'sleep_interval' => $sleep
        ]);

        $videoInfo = $this->bilibiliService->getVideoInfo($video->bvid);
        if (!$videoInfo) {
            // [建议] 增加失败日志
            Log::warning('Failed to fetch video info for comments', ['bvid' => $video->bvid]);
            return;
        }
        $aid = $videoInfo['aid'];


        // [新增] 优先使用自定义数量
        if ($customLimit !== null) {
            $targetCount = $customLimit;
            // [修改] 统一日志格式
            Log::info('Using custom comment limit', ['count' => $targetCount]);
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
            
            $viewScore = $viewCount > 0 ? min(40, floor(log10($viewCount) * 2)) : 0;
            $replyScore = $replyCount > 0 ? min(40, floor(log10($replyCount) * 3)) : 0;
            
            $targetCount = min(100, $baseCount + $viewScore + $replyScore);
            
            // [修改] 统一日志格式：将计算参数放入 Context
            Log::info('Adaptive comment count calculated', [
                'target_count' => $targetCount,
                'view_count'   => $viewCount,
                'reply_count'  => $replyCount
            ]);
        }
        // 获取评论数据
        $commentsData = $this->bilibiliService->getVideoComments($aid, $targetCount);

        if (empty($commentsData)) {
            Log::info('No comments found', ['bvid' => $video->bvid]);
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

        // [修改] 统一日志格式
        Log::info('Comments download completed', [
            'video_id' => $video->id,
            'title'    => $video->title,
            'count'    => count($commentsData)
        ]);
    }
}