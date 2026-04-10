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
        Log::info('[视频评论] 开始评论下载任务', [
            'video_id' => $video->id,
            'title'    => $video->title,
            'bvid'     => $video->bvid,
            'sleep_interval' => $sleep
        ]);

        // 【核心修改】加入 try-catch 拦截底层抛出的异常
        try {
            $videoInfo = $this->bilibiliService->getVideoInfo($video->bvid);
        } catch (\App\Exceptions\ApiGetVideoStatusException $e) {
            // 拦截业务状态异常：如 62002(稿件不可见), 62012, 62004 等
            Log::warning('[视频评论] 视频状态异常(62002, 62012, 62004), 跳过评论下载', [
                'bvid' => $video->bvid,
                'code' => $e->getCode(),
                'msg'  => $e->getMessage()
            ]);
            return; // 直接 return，不抛出异常，任务会顺利结束(DONE)不会重试
        } catch (\Exception $e) {
            // 拦截常规异常：如 -404(啥都木有/视频被删)
            if (in_array($e->getCode(), [-404, -403])) {
                Log::warning('[视频评论] 视频状态异常(啥都木有, 视频被删), 跳过评论下载', [
                    'bvid' => $video->bvid,
                    'code' => $e->getCode(),
                    'msg'  => $e->getMessage()
                ]);
                return; 
            }
            
            // 如果遇到真的未知的偶发错误，记录简洁错误并抛出（保留栈追踪，并让队列重试机制生效）
            Log::error('[视频评论] 视频状态异常(Unknown error fetching video info for comments), 跳过评论下载', [
                'bvid' => $video->bvid,
                'code' => $e->getCode(),
                'msg'  => $e->getMessage()
            ]);
            throw $e;
        }
        
        if (!$videoInfo) {
            // [建议] 增加失败日志
            Log::warning('[视频评论] 视频状态异常(Failed to fetch video info for comments), 跳过评论下载', ['bvid' => $video->bvid]);
            return;
        }
        $aid = $videoInfo['aid'];


        // [新增] 优先使用自定义数量
        if ($customLimit !== null) {
            $targetCount = $customLimit;
            // [修改] 统一日志格式
            Log::info('[视频评论] 使用自定义数量', ['count' => $targetCount]);
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
            Log::info('[视频评论] 计算并使用自适应数量', [
                'target_count' => $targetCount,
                'view_count'   => $viewCount,
                'reply_count'  => $replyCount
            ]);
        }
        // 获取评论数据
        $commentsData = $this->bilibiliService->getVideoComments($aid, $targetCount);

        if (empty($commentsData)) {
            Log::info('[视频评论] 未找到评论', ['bvid' => $video->bvid]);
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

            // 3. 【新增】处理评论区用户头像
            // B站API通常将用户信息放在 member 对象下，头像字段为 avatar
            if (isset($data['avatar']) && !empty($data['avatar'])) {
                $data['avatar'] = $this->imageService->processAvatar($data['avatar']);
            }

            // 写入主评论
            Comment::updateOrCreate(
                ['rpid' => $data['rpid'], 'video_id' => $video->id],
                $data
            );
        }
        unset($data); // 解除引用

        // [修改] 统一日志格式
        Log::info('[视频评论] 评论下载完成', [
            'video_id' => $video->id,
            'title'    => $video->title,
            'count'    => count($commentsData)
        ]);
    }
}