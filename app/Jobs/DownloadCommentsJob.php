<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoManager\Actions\Video\DownloadCommentsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadCommentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // [新增] 允许最大尝试次数为 3 次
    public $tries = 3;

    // [新增] 允许任务最长运行 1800 秒 (30 分钟)
    // 假设 100条主评论 * 每条带子评论，sleep下来可能要几分钟，设大一点安全
    public $timeout = 1800; 

    // [新增] 失败后重试的延迟时间 (例如遇到 412 风控时，先休息几分钟再试)
    public $backoff = [60, 300, 600];
    
    // [修改] 增加 limit 属性
    // [修改] 增加 sleep 属性
    public function __construct(
        public Video $video,
        public ?int $limit = null,
        public int $sleep = 3
    )
    {
        // 指定 comments 队列
        $this->onQueue('comments');
    }

    public function handle(DownloadCommentsAction $action): void
    {
        // [修改] 将 limit 传给 Action
        // [修改] 将 sleep 传给 Action
        $action->execute($this->video, $this->limit, $this->sleep);
    }
}