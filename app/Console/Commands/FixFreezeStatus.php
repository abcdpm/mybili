<?php
namespace App\Console\Commands;

use App\Jobs\RebuildVideosCacheJob;
use App\Models\AudioPart;
use App\Models\Video;
use App\Models\VideoPart;
use Illuminate\Console\Command;

/**
 * 修复视频冻结状态
 * @package App\Console\Commands
 */
class FixFreezeStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-freeze-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $videos = Video::where('frozen', true)->where('invalid', true)->get();
        $videos->each(function ($video) {
            if ($video->video_downloaded_num == 0 && $video->audio_downloaded_num == 0) {
                // video
                $videoDownloadedNum = VideoPart::where('video_id', $video->id)->whereNotNull('video_downloaded_at')->count();
                $video->video_downloaded_num = $videoDownloadedNum;
                // audio
                $audioDownloadedNum = AudioPart::where('video_id', $video->id)->whereNotNull('audio_downloaded_at')->count();
                $video->audio_downloaded_num = $audioDownloadedNum;
             
                $video->frozen = ($videoDownloadedNum > 0 || $audioDownloadedNum > 0) ? true : false;

                $video->save();

                $this->info('修复视频冻结状态完成: ' . $video->id . ' ' . $video->title);
            }
        });
        RebuildVideosCacheJob::dispatch()
            ->delay(now()->addSeconds(2))
            ->onQueue('fast');
        $this->info('修复视频冻结状态完成');
    }
}
