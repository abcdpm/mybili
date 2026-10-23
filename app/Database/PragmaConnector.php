<?php

namespace App\Database;

use Illuminate\Database\Connectors\SQLiteConnector;

/**
 * SQLite 连接器扩展：连接建立后自动应用 PRAGMA 配置。
 *
 * Laravel 11 的 SQLiteConnector 尚不支持 busy_timeout / journal_mode 等
 * PRAGMA 配置项（Laravel 12+ 才原生支持），因此这里自定义连接器，
 * 让所有 SQLite 连接（Web / Octane / 队列 Worker / Artisan 命令）统一获得：
 *
 *   - journal_mode=WAL   多进程并发读写不互相阻塞，写入吞吐显著提升
 *   - busy_timeout=N     写锁竞争时等待 N 毫秒，而不是立刻抛 database is locked
 *   - synchronous=NORMAL WAL 下推荐级别，兼顾安全与性能
 *   - foreign_keys=ON    与 config 中 foreign_key_constraints 保持一致
 *
 * 兼容性说明：
 *   - 配置全部来自 config/database.php 的 sqlite 连接，未配置时使用安全默认值；
 *   - 取值经白名单校验，杜绝配置注入；
 *   - 任何一条 PRAGMA 失败都不会阻断连接建立（如 :memory: 库不支持 WAL 时静默降级），
 *     老环境用户无需修改任何配置即可平滑获得上述优化。
 */
class PragmaConnector extends SQLiteConnector
{
    /** 允许的 journal_mode 取值（白名单，防注入） */
    private const JOURNAL_MODES = ['delete', 'truncate', 'persist', 'memory', 'wal', 'off'];

    /** 允许的 synchronous 取值（白名单，防注入） */
    private const SYNCHRONOUS_MODES = ['off', 'normal', 'full', 'extra'];

    /**
     * 建立数据库连接并应用 PRAGMA。
     */
    public function connect(array $config)
    {
        $pdo = parent::connect($config);

        foreach ($this->pragmas($config) as $pragma) {
            try {
                $pdo->exec($pragma);
            } catch (\Throwable $e) {
                // 降级保护：PRAGMA 失败（如内存库、只读文件）不应阻断连接
                try {
                    if (function_exists('logger')) {
                        logger()->warning('[SQLite] PRAGMA 执行失败: '.$pragma.' - '.$e->getMessage());
                    }
                } catch (\Throwable $ignored) {
                    // 日志设施未就绪时静默忽略
                }
            }
        }

        return $pdo;
    }

    /**
     * 根据连接配置生成 PRAGMA 语句列表。
     *
     * @return array<int, string>
     */
    private function pragmas(array $config): array
    {
        $pragmas = [];

        // busy_timeout：写锁等待毫秒数
        $busyTimeout = (int) ($config['busy_timeout'] ?? 5000);
        if ($busyTimeout < 0) {
            $busyTimeout = 5000;
        }
        $pragmas[] = 'PRAGMA busy_timeout = '.$busyTimeout;

        // journal_mode：WAL 为推荐值（持久化到库文件，一次设置全局生效）
        $journalMode = strtolower((string) ($config['journal_mode'] ?? 'wal'));
        if (in_array($journalMode, self::JOURNAL_MODES, true)) {
            $pragmas[] = 'PRAGMA journal_mode = '.$journalMode;
        }

        // synchronous：WAL 下 normal 即安全又快
        $synchronous = strtolower((string) ($config['synchronous'] ?? 'normal'));
        if (in_array($synchronous, self::SYNCHRONOUS_MODES, true)) {
            $pragmas[] = 'PRAGMA synchronous = '.$synchronous;
        }

        // foreign_keys：与 Laravel 的 foreign_key_constraints 配置保持一致
        $foreignKeys = filter_var($config['foreign_key_constraints'] ?? $config['foreign_keys'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $pragmas[] = 'PRAGMA foreign_keys = '.($foreignKeys ? 'ON' : 'OFF');

        // ==== 以下为性能调优（沿用历史默认值，均可通过连接配置覆盖）====
        // wal_autocheckpoint：WAL 自动 checkpoint 阈值（页数）
        $pragmas[] = 'PRAGMA wal_autocheckpoint = '.(int) ($config['wal_autocheckpoint'] ?? 2000);

        // journal_size_limit：WAL 文件大小上限（字节），checkpoint 后自动截断
        $pragmas[] = 'PRAGMA journal_size_limit = '.(int) ($config['journal_size_limit'] ?? 67108864);

        // cache_size：页缓存（负数为 KB，-8000 即约 8MB/连接）。
        // 【内存治理】旧默认 -20000（20MB/连接）偏大：评论库拆分后每进程持有
        // 主库+评论库两个连接，上限叠加；8MB 对 videos（2.4 万行）与按视频取
        // 评论的索引范围读都足够，全体 worker 可省数百 MB。
        $pragmas[] = 'PRAGMA cache_size = '.(int) ($config['cache_size'] ?? -8000);

        // temp_store：临时表放文件（1）而非内存（2）。
        // 【内存治理】大排序（如 ORDER BY RANDOM() 全表抽样、千万行聚合）在
        // temp_store=MEMORY 时临时表吃进内存，峰值可达 GB 级；文件模式内存有界。
        $pragmas[] = 'PRAGMA temp_store = '.(int) ($config['temp_store'] ?? 1);

        // mmap_size：内存映射大小（字节）。
        // 【内存治理】旧默认 256MB 偏大：被触碰的映射页计入 RSS/Docker 内存，
        // 两个连接叠加后虚胖；32MB 覆盖热点页即可。
        $pragmas[] = 'PRAGMA mmap_size = '.(int) ($config['mmap_size'] ?? 33554432);

        return $pragmas;
    }
}
