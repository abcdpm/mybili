<?php

namespace App\Services\VideoManager\Actions\Video;

use App\Models\Comment;
use App\Models\CommentUser;
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

        // 图片本地化 + 用户归一化 + 批量写入（第4项性能优化）
        // 旧版逐条 updateOrCreate（每条评论 2 条 SQL + 每用户 1 条 upsert），
        // 100 条评论规模 = 300+ 次 SQL 往返；现聚合成 2 次批量 upsert。
        $userRows    = [];   // mid => 行（同用户多条评论自动去重）
        $commentRows = [];

        foreach ($commentsData as $data) {
            // 1. 表情包本地化
            if (! empty($data['emotes'])) {
                $data['emotes'] = $this->imageService->processEmotes($data['emotes']);
            }

            // 2. 评论配图本地化
            if (! empty($data['pictures'])) {
                $data['pictures'] = $this->imageService->processPictures($data['pictures']);
            }

            // 3. 头像本地化
            if (! empty($data['avatar'])) {
                $data['avatar'] = $this->imageService->processAvatar($data['avatar']);
            }

            // 4. 用户信息归一化：收集 comment_users 行（按 mid 一行）
            $userId = (int) ($data['mid'] ?? 0);
            if ($userId > 0) {
                $userRows[$userId] = [
                    'mid'        => $userId,
                    'uname'      => (string) ($data['uname'] ?? ''),
                    'avatar'     => (string) ($data['avatar'] ?? ''),
                    'updated_at' => now(),
                ];
            }

            // 5. 表情映射压缩为 key 列表（仅当全部表情已本地化时才压缩，
            //    存在远程 URL 兜底值时保留完整映射，避免展示丢失）
            $emotes = $data['emotes'] ?? null;
            if (! empty($emotes) && is_array($emotes)) {
                $allLocalized = ! in_array(false, array_map(
                    fn ($v) => is_array($v) && isset($v['webp']),
                    $emotes
                ), true);
                if ($allLocalized) {
                    $emotes = array_values(array_keys($emotes));
                }
            }

            // 6. 组装评论行：uname/avatar 归一化置空（权威数据在 comment_users），
            //    无 mid 的异常行保留原值兜底；JSON 字段手动编码（批量 upsert 不经 Eloquent cast）
            $commentRows[] = [
                'rpid'       => (int) $data['rpid'],
                'video_id'   => $video->id,
                'oid'        => (int) ($data['oid'] ?? 0),
                'mid'        => $userId,
                'uname'      => $userId > 0 ? '' : (string) ($data['uname'] ?? ''),
                'avatar'     => $userId > 0 ? '' : (string) ($data['avatar'] ?? ''),
                'content'    => (string) ($data['content'] ?? ''),
                'like'       => (int) ($data['like'] ?? 0),
                'root'       => (int) ($data['root'] ?? 0),
                'parent'     => (int) ($data['parent'] ?? 0),
                'ctime'      => $data['ctime'] ?? now(),
                'pictures'   => json_encode($data['pictures'] ?? [], JSON_UNESCAPED_UNICODE),
                'emotes'     => json_encode($emotes ?? [], JSON_UNESCAPED_UNICODE),
                'is_top'     => (int) (bool) ($data['is_top'] ?? false),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // 批量写入（rpid 全局唯一；冲突时更新内容列）
        if (! empty($userRows)) {
            CommentUser::query()->upsert(
                array_values($userRows),
                ['mid'],
                ['uname', 'avatar', 'updated_at']
            );
        }
        if (! empty($commentRows)) {
            Comment::query()->upsert(
                $commentRows,
                ['rpid'],
                ['oid', 'mid', 'uname', 'avatar', 'content', 'like', 'root', 'parent',
                 'ctime', 'pictures', 'emotes', 'is_top', 'updated_at']
            );
        }
        // [修改] 统一日志格式
        Log::info('[视频评论] 评论下载完成', [
            'video_id' => $video->id,
            'title'    => $video->title,
            'count'    => count($commentsData)
        ]);
    }
}