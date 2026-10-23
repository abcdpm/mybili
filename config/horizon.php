<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 双 Supervisor 说明
    |--------------------------------------------------------------------------
    | - default: 处理 default / fast / slow，不限流（如 ProcessDownloadQueue、统计等）
    | - bilibili-rate-limit: 仅处理 bilibili-rate-limit 队列，限流 Job 执行 B 站 API/下载，
    |   遇 412 风控时 Job 会 暂停 Horizon supervisor-bilibili-rate-limit, 并在延迟任务后自动恢复
    */

    'defaults' => [
        // 专门处理常规任务
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['fast', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // 【内存治理】10 → 6：每 worker 常驻 60-80MB，个人媒体库场景
            // 队列吞吐远未饱和；需要更高并发可回调至 8-10
            'maxProcesses' => 6,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 1600,
            'nice' => 0,
        ],
        'supervisor-slow' => [
            'connection' => 'redis',
            'queue' => ['slow'],
            'balance' => 'simple',
            'processes' => 2,
            'tries' => 2,
            'timeout' => 1800,
        ],
        'supervisor-bilibili-rate-limit' => [
            'connection' => 'redis',
            'queue' => ['bilibili-rate-limit'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 1600,
            'nice' => 0,
        ],
        // 专门处理转码任务
        'supervisor-transcode' => [
            'connection' => 'redis',
            'queue' => ['transcode'],
            'balance' => 'simple',
            'processes' => 2,       // 限制同时转码的数量，防止服务器 CPU 爆表
            'tries' => 1,           // 转码失败通常不需要立即重试
            'timeout' => 3600,      // 确保超时时间足够长
        ],
        // 专门处理评论下载的队列
        'supervisor-comments' => [
            'connection' => 'redis',
            'queue' => ['comments', 'comments-batch'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 1800, // 与 Job 中的 $timeout = 1800 保持一致
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            // 【内存治理】合并 fast/default/slow/comments/comments-batch 到单一 supervisor：
            // 闲置仅 1 个 worker（auto 弹性 min1/max3），峰值按需扩容；
            // 长任务的 timeout/tries 由 Job 自身属性覆盖（如 DownloadCommentsJob $tries=3）。
            // 如需恢复队列隔离或更高吞吐，可拆回独立 supervisor 并提高 maxProcesses。
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['fast', 'default', 'slow', 'comments', 'comments-batch'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries' => 3,
                'timeout' => 1800,
            ],
            // 限流队列保持独立：BilibiliSuspendService 按本 supervisor 名称做 412 暂停/恢复，勿改名
            'supervisor-bilibili-rate-limit' => [
                'connection' => 'redis',
                'queue' => ['bilibili-rate-limit'],
                'balance' => 'simple',
                'processes' => 1,
                'tries' => 3,
                'timeout' => 60,
            ],
            // 转码保持独立：ffmpeg 长任务防止占满通用 worker
            'supervisor-transcode' => [
                'connection' => 'redis',
                'queue' => ['transcode'],
                'balance' => 'simple',
                'processes' => 1,
                'tries' => 1,
                'timeout' => 3600,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['fast', 'default'],
                'balance' => 'auto',
                'processes' => 10,
                'tries' => 3,
                'timeout' => 1800,
            ],
            'supervisor-slow' => [
                'connection' => 'redis',
                'queue' => ['slow'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 2,
                'timeout' => 1800,
            ],
            'supervisor-bilibili-rate-limit' => [
                'connection' => 'redis',
                'queue' => ['bilibili-rate-limit'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 3,
                'timeout' => 60,
            ],
            // 专门处理转码任务
            'supervisor-transcode' => [
                'connection' => 'redis',
                'queue' => ['transcode'],
                'balance' => 'simple',
                'processes' => 2,       // 限制同时转码的数量，防止服务器 CPU 爆表
                'tries' => 1,           // 转码失败通常不需要立即重试
                'timeout' => 3600,      // 确保超时时间足够长
            ],
            // 专门处理评论下载任务
            'supervisor-comments' => [
                'connection' => 'redis',
                'queue' => ['comments', 'comments-batch'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 3,
                'timeout' => 1800,
            ],
        ],
    ],
];
