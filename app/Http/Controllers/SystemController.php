<?php
namespace App\Http\Controllers;

use App\Services\SystemService;
use Illuminate\Http\Request; // 【新增】必须引入 Request 类

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
}
