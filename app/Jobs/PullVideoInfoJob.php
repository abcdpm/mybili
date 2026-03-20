<?php
namespace App\Jobs;

use App\Services\VideoManager\Actions\Video\PullVideoInfoAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullVideoInfoJob implements ShouldQueue 
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 设置普通队列的最大重试次数
    public $tries = 3;
    
    // 设置任务超时时间
    public $timeout = 120;  

    public function __construct(public string $bvid)
    {
        // 如果你需要强制指定它进入特定的常规队列（比如 default），可以显式声明：
        $this->onQueue('default'); 
    }

    public function handle(): void
    {
        app(PullVideoInfoAction::class)->execute($this->bvid);
    }
}
