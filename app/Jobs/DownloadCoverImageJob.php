<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use App\Services\CoverService;
use Laravel\Horizon\Contracts\Silenced;

// 【新增】引入队列必须的 Traits
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
/**
 * 新的下载封面任务处理
 */
class DownloadCoverImageJob implements ShouldQueue, Silenced
{

    // 【新增】使用这些 Traits，确保 Model 能被正确序列化和反序列化
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // public $queue = 'fast';
    
    /**
     * 最大重试次数
     */
    public $tries = 3;
    
    /**
     * 任务失败前等待的时间（以秒为单位）
     */
    public $backoff = [60, 300, 600];
    /**
     * Create a new job instance.
     */
    public function __construct(
        private string $url,
        private string $type,
        private Model $model
    )
    {
        // 指定 fast 队列
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(CoverService $coverService): void
    {
        if(empty($this->url)){
            return;
        }
        $coverService->downloadCover($this->url, $this->type, $this->model);
    }
}
