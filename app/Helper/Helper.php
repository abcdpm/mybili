<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;

/**
 * Get redis cache instance
 */
function cache_redis(): \Illuminate\Contracts\Cache\Repository
{
    return Cache::store('redis');
}

/**
 * Get redis instance
 *
 * @return mixed|Redis
 */
function redis()
{
    return \Illuminate\Support\Facades\Redis::connection()->client();
}

function video_has_invalid(array $videoInfo)
{
    if ($videoInfo['title'] == '已失效视频') {
        return true;
    }

    if ($videoInfo['attr'] > 0) {
        return true;
    }

    return false;
}

function parse_netscape_cookie_file($filename)
{

    if (! is_file($filename)) {
        throw new ErrorException('cookie.txt 不存在');
    }

    $cookies = [];
    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if ($line[0] === '#' || substr_count($line, "\t") < 6) {
            continue; // Skip comments and invalid lines
        }

        [$domain, $flag, $path, $secure, $expiry, $name, $value] = explode("\t", $line);

        $cookies[] = new SetCookie([
            'Domain' => $domain,
            'Path' => $path,
            'Name' => $name,
            'Value' => $value,
            'Expires' => $expiry,
            'Secure' => ($secure === 'TRUE'),
        ]);
    }

    return new CookieJar(false, $cookies);
}

function parse_netscape_cookie_content($content)
{
    if (empty($content)) {
        if (config('services.bilibili.ignore_cookies')) {
            return new CookieJar(false, []);
        }
        throw new \InvalidArgumentException('Cookie 内容不能为空');
    }

    $cookies = [];
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || ($line[0] ?? '') === '#' || substr_count($line, "\t") < 6) {
            continue; // 跳过注释和无效行
        }

        $parts = explode("\t", $line);
        if (count($parts) < 7) {
            continue; // 确保有足够的字段
        }

        [$domain, $flag, $path, $secure, $expiry, $name, $value] = $parts;

        $cookies[] = new SetCookie([
            'Domain' => $domain,
            'Path' => $path,
            'Name' => $name,
            'Value' => $value,
            'Expires' => $expiry,
            'Secure' => ($secure === 'TRUE'),
        ]);
    }

    return new CookieJar(false, $cookies);
}

function get_relative_path(string $absolutePath): string
{
    // 如果是 storage 路径，转换为 URL
    if (Str::startsWith($absolutePath, storage_path('app/public'))) {
        return Str::after($absolutePath, storage_path('app/public/'));
    }

    // 如果是 public 路径，转换为相对路径
    if (Str::startsWith($absolutePath, public_path())) {
        return Str::after($absolutePath, public_path());
    }

    return $absolutePath;
}

function usage_analytics_enabled(): bool
{
    // 严格 opt-in：仅当用户在设置中显式开启时才启用使用情况统计；
    // 未设置 / 关闭 / 配置缺失均视为关闭（保护隐私，见 README「隐私声明」）
    return app(SettingsService::class)->get(SettingKey::USAGE_ANALYTICS_ENABLED->value) === 'on';
}

/**
 * Convert bytes to human readable file size
 * Maximum unit is GB, minimum unit is MB
 */
function format_file_size(int $bytes): string
{
    if ($bytes === 0) {
        return '0 MB';
    }

    $gb = $bytes / (1024 * 1024 * 1024);
    if ($gb >= 1) {
        return round($gb, 2).' GB';
    }

    $mb = $bytes / (1024 * 1024);

    return round($mb, 2).' MB';
}

/**
 * Format duration from seconds to readable format (HH:MM:SS or MM:SS)
 */
function format_duration(string $duration): string
{
    // Convert duration to integer (assuming it's in seconds)
    return gmdate('H:i:s', intval($duration));
}

/**
 * 评论库拆分标记文件路径（批F·架构拆分）。
 *
 * 标记由 app:split-comments-db --execute 在完成"复制+校验"后写入，
 * 删除标记（--rollback）即回退到主库旧表 —— 全程零删除、可逆。
 */
function comments_db_marker_path(): string
{
    return config('database.comments_split_marker', storage_path('app/comments-db-split.json'));
}

/**
 * 评论库拆分是否已激活（进程级缓存，每进程只探测一次标记文件）。
 * 用 comments_db_split_flush() 清除缓存（切换/回滚后立即感知新状态）。
 */
function comments_db_split_active(): bool
{
    if (CommentsSplitState::$active === null) {
        CommentsSplitState::$active = file_exists(comments_db_marker_path());
    }

    return CommentsSplitState::$active;
}

/**
 * 清除拆分标记的进程级缓存。
 */
function comments_db_split_flush(): void
{
    CommentsSplitState::$active = null;
}

/**
 * 拆分标记的进程级状态载体（便于测试与运行时重置）。
 */
class CommentsSplitState
{
    public static ?bool $active = null;
}

/**
 * 取评论数据库连接（批F·架构拆分路由）。
 *
 * 未拆分：返回默认连接（主库）—— 与旧行为完全一致；
 * 已拆分：返回 sqlite_comments 连接（comments/comment_users/emotes 三表所在库）。
 * 所有评论三表的原生查询（DB::table）必须经由本助手，Eloquent 模型走
 * UsesCommentsDatabase trait，保证拆分/回滚对业务代码透明。
 */
function comments_db(): \Illuminate\Database\Connection
{
    $connection = comments_db_split_active() ? 'sqlite_comments' : config('database.default');

    return \Illuminate\Support\Facades\DB::connection($connection);
}
