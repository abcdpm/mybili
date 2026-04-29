<?php
namespace App\Http\Controllers;

use App\Services\SystemService;
use Illuminate\Http\Request; // 【新增】必须引入 Request 类
use Illuminate\Support\Facades\Artisan;

class SystemController extends Controller
{

    public function __construct(public SystemService $systemService)
    {
    }

    public function getSystemInfo()
    {
        return response()->json($this->systemService->getSystemInfo());
    }

    /**
     * 获取系统运行日志 (最后 1000 行)
     */
    public function logs(Request $request)
    {
        // 接收前端传来的日志类型，默认为 laravel
        $type = $request->input('type', 'laravel');

        // 【关键】定义系统中不同日志的绝对物理路径
        $logPaths = [
            'laravel'    => storage_path('logs/laravel.log'),
            'supervisor' => '/var/log/supervisord.log', // 这是 Docker 中最常见的 supervisor 日志路径
            // 以后如果有需要，可以随时在这里继续加 'nginx' => '/var/log/nginx/access.log' 等等
        ];
        $logFile = $logPaths[$type] ?? null;

        // 如果文件不存在，友善地返回报错提示并带上路径，方便排查
        if (!$logFile || !file_exists($logFile)) {
            return response()->json(['data' => "No logs found for [{$type}] at path: {$logFile}"]);
        }
        
        // 使用系统级 tail 命令极速读取，避免大文件卡死内存
        exec('tail -n 1000 ' . escapeshellarg($logFile), $output);
        return response()->json(['data' => implode("\n", $output)]);
    }

    /**
     * 清空物理日志文件
     */
    public function clearLogs(Request $request)
    {
        $type = $request->input('type', 'laravel');

        $logPaths = [
            'laravel'    => storage_path('logs/laravel.log'),
            'supervisor' => '/var/log/supervisord.log',
        ];
        $logFile = $logPaths[$type] ?? null;

        if (!$logFile || !file_exists($logFile)) {
            return response()->json(['success' => false, 'message' => "日志文件不存在"], 404);
        }

        try {
            // 将文件内容置空 (物理截断)
            file_put_contents($logFile, '');
            return response()->json(['success' => true, 'message' => '日志已清空']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => '清空失败: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 运维小工具：获取 Horizon 队列积压统计
     */
    public function queueStats(Request $request)
    {
        $queueName = $request->input('queue', 'default'); 
        $connection = 'redis';

        try {
            // 获取 Redis 实例
            $redis = app('queue')->connection($connection)->getRedis()->connection();
            
            // 获取队列全名 (底层依赖库会自动处理 prefix)
            $queueKey = 'queues:' . $queueName;

            // 抽样取出前 100000 条任务 (不会删除任务)
            $jobs = $redis->lrange($queueKey, 0, 100000);

            $stats = [];
            foreach ($jobs as $jobJson) {
                $job = json_decode($jobJson, true);
                $commandName = $job['displayName'] ?? 'Unknown';

                // 简化类名显示
                $parts = explode('\\', $commandName);
                $shortName = end($parts);

                if (!isset($stats[$shortName])) {
                    $stats[$shortName] = 0;
                }
                $stats[$shortName]++;
            }

            // 按数量降序排列
            arsort($stats);

            // 格式化为前端易于渲染的数组
            $result = [];
            foreach ($stats as $name => $count) {
                $result[] = [
                    'name' => $name,
                    'count' => $count
                ];
            }

            return response()->json(['data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function getMediaUsage()
    {
        return response()->json($this->systemService->getMediaUsage());
    }

/**
     * 获取高级运维工具箱下拉框所需的动态元数据
     */
    public function getMaintenanceMetadata()
    {
        try {
            // 获取所有收藏夹
            $favs = \App\Models\FavoriteList::all()->map(function($item) {
                return ['id' => $item->id, 'name' => $item->title ?? $item->name ?? 'Unknown'];
            });

            // 获取所有订阅
            $subs = \App\Models\Subscription::all()->map(function($item) {
                return ['id' => $item->id, 'name' => $item->name ?? $item->title ?? 'Unknown'];
            });

            // 智能提取 Horizon 队列配置
            $queues = [];
            foreach (config('horizon.environments', []) as $env => $supervisors) {
                foreach ($supervisors as $supervisor => $options) {
                    if (isset($options['queue'])) {
                        $q = is_array($options['queue']) ? $options['queue'] : explode(',', $options['queue']);
                        $queues = array_merge($queues, $q);
                    }
                }
            }
            foreach (config('horizon.defaults', []) as $supervisor => $options) {
                if (isset($options['queue'])) {
                    $q = is_array($options['queue']) ? $options['queue'] : explode(',', $options['queue']);
                    $queues = array_merge($queues, $q);
                }
            }
            
            $baseQueues = ['default', 'fast', 'slow', 'comments', 'comments-batch', 'transcode'];
            $allQueues = array_values(array_unique(array_merge($baseQueues, $queues)));

            return response()->json([
                'success' => true,
                'data' => [
                    'favs' => $favs,
                    'subs' => $subs,
                    'queues' => $allQueues
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 执行高级运维工具箱的命令
     */
    public function runMaintenanceCommand(Request $request)
    {
        // 【关键修复 1】解除 PHP 默认的 30 秒运行时间限制！
        // 保证像“扫盘转码”、“生成可读文件名”这样耗时几分钟的大任务不会被 PHP 中途强杀。
        set_time_limit(0);

        $command = $request->input('command');
        
        try {
            switch ($command) {
                case 'sync-fav-list': 
                    Artisan::call('app:sync-media', ['--fav-list' => true]);
                    break;
                    
                case 'sync-target': 
                    $params = [];
                    $type = $request->input('type');
                    if ($type === 'fav') {
                        $params['--fav-videos'] = true;
                    } else {
                        $params['--subscriptions'] = true;
                    }
                    if ($request->filled('favId')) {
                        $params['--fav'] = $request->input('favId');
                    }
                    if ($request->boolean('page1')) {
                        $params['--fav-page'] = 1;
                    }
                    Artisan::call('app:sync-media', $params);
                    break;

                case 'sync-all-favs': 
                    Artisan::call('app:sync-media', ['--fav-videos' => true]);
                    break;
                    
                case 'sync-all-subs': 
                    Artisan::call('app:sync-media', ['--subscriptions' => true]);
                    break;

                case 'scan-cover': 
                    Artisan::call('app:scan-cover-image', ['--target' => $request->input('target')]);
                    break;

                case 'make-readable': 
                    Artisan::call('app:make-human-readable-names');
                    break;

                case 'transcode-all': 
                    Artisan::call('app:transcode-all'); 
                    break;

                case 'download-comments': 
                    // 【关键修复 2】补上了结尾的 's'，匹配你真实的 command 签名
                    Artisan::call('app:download-all-comments');
                    break;

                case 'download-tags': 
                    Artisan::call('app:download-tags');
                    break;

                case 'download-danmaku': 
                    Artisan::call('app:danmaku-download');
                    break;

                case 'update-stats': 
                    Artisan::call('app:update-video-stats');
                    break;

                case 'clear-queue': 
                    $queue = $request->input('queue');
                    
                    if ($queue === 'all') {
                        // 原生 Artisan::call 是安全的，不涉及底层 Shell
                        Artisan::call('queue:flush');
                    } else {
                        // ==========================================
                        // 🛡️ 安全防护 1：严格正则表达式（白名单校验）
                        // 强制要求队列名称只能包含字母、数字、下划线(_)和中划线(-)
                        // 彻底阻断任何特殊字符（如 ; | & $ > < ` 等）的注入企图
                        // ==========================================
                        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $queue)) {
                            throw new \Exception("安全警告：检测到非法的队列名称参数，操作已拦截！");
                        }

                        // ==========================================
                        // 🛡️ 安全防护 2：escapeshellarg 二次包裹
                        // 确保传递给操作系统的参数被强行视为纯字符串
                        // ==========================================
                        $artisanPath = base_path('artisan');
                        $safeQueue = escapeshellarg($queue);
                        
                        exec("php {$artisanPath} horizon:clear --queue={$safeQueue}");
                    }
                    break;

                case 'calc-stats': 
                    Artisan::call('app:calculate-system-stats');
                    break;

                default:
                    return response()->json(['success' => false, 'message' => '未知命令'], 400);
            }
            return response()->json(['success' => true, 'message' => '命令 ['.$command.'] 触发成功，请查看日志关注进度。']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => '执行失败: ' . $e->getMessage()], 500);
        }
    }
}
