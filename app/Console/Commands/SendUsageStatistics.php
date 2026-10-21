<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UsageStatisticsService;

class SendUsageStatistics extends Command
{
    protected $signature = 'stats:send';
    protected $description = 'Send anonymous usage statistics';

    private UsageStatisticsService $statsService;

    public function __construct(UsageStatisticsService $statsService)
    {
        parent::__construct();
        $this->statsService = $statsService;
    }

    public function handle()
    {
        // 隐私保护：仅在用户于设置页显式开启「使用情况统计」时才上报（严格 opt-in）
        if (! usage_analytics_enabled()) {
            $this->info('使用情况统计未开启（设置页可开启），跳过上报。');

            return;
        }

        $this->info('Sending usage statistics...');
        $this->statsService->sendStats();
        $this->info('Usage statistics sent successfully.');
    }
} 