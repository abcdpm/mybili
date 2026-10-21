<?php

namespace App\Services\Danmaku;

use App\Models\Danmaku;
use App\Models\DanmakuFile;
use Illuminate\Support\Facades\Log;

/**
 * 弹幕文件化存储。
 *
 * 设计目标（兼容老环境平滑升级）：
 *   - 新下载的弹幕按 cid 写入 JSON.gz 单文件（storage/app/public/danmaku/{cid}.json.gz），
 *     数据库不再承载弹幕正文，仅保留 danmaku_files 索引（cid → 条数）；
 *   - 读取时「文件优先 + 数据库遗留行兜底」并按弹幕 id 去重合并，
 *     老版本遗留在 danmaku 表中的数据在迁移前依然可以正常播放；
 *   - 写入时若发现该 cid 存在数据库遗留行，会先并入文件、校验写入成功后
 *     再清理遗留行（自迁移），全程不丢数据；
 *   - 全量迁移请使用 php artisan app:danmaku-migrate-to-files。
 */
class DanmakuFileStore
{
    /**
     * 弹幕存储根目录（相对字段 path 记录为相对 storage/app/public 的路径）。
     */
    public function basePath(): string
    {
        $path = (string) config('danmaku.path', storage_path('app/public/danmaku'));

        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * 指定 cid 的弹幕文件绝对路径。
     */
    public function filePath(int|string $cid): string
    {
        return $this->basePath() . DIRECTORY_SEPARATOR . $cid . '.json.gz';
    }

    public function exists(int|string $cid): bool
    {
        return is_file($this->filePath($cid));
    }

    /**
     * 读取文件中的弹幕列表（损坏时返回空数组并记录日志，不抛异常）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(int|string $cid): array
    {
        $path = $this->filePath($cid);
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            Log::error('[弹幕存储] 弹幕文件读取失败', ['cid' => $cid, 'path' => $path]);

            return [];
        }

        // 兼容非压缩内容（防御性）
        $json = str_starts_with($raw, "\x1f\x8b") ? @gzdecode($raw) : $raw;
        if ($json === false || $json === null) {
            Log::error('[弹幕存储] 弹幕文件解压失败', ['cid' => $cid, 'path' => $path]);

            return [];
        }

        $items = json_decode($json, true);
        if (! is_array($items)) {
            Log::error('[弹幕存储] 弹幕文件内容损坏', ['cid' => $cid, 'path' => $path]);

            return [];
        }

        return array_values($items);
    }

    /**
     * 读取数据库中的遗留弹幕行（老版本写入、尚未迁移的数据）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function legacyRows(int|string $cid): array
    {
        return Danmaku::query()
            ->where('cid', $cid)
            ->get(['id', 'progress', 'mode', 'color', 'content'])
            ->toArray();
    }

    /**
     * 合并读取（文件 + 数据库遗留行），按弹幕 id 去重。
     *
     * @return array<int, array<string, mixed>>
     */
    public function merged(int|string $cid): array
    {
        $items = array_merge($this->read($cid), $this->legacyRows($cid));

        return array_values($this->dedupe($items));
    }

    /**
     * 保存弹幕：与已有数据（文件 + 遗留行）合并去重后写入文件。
     *
     * 写入成功后会将该 cid 的数据库遗留行清理掉（数据已完整保存在文件中），
     * 并同步 danmaku_files 索引。任何一步失败都不会丢失已有数据。
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return int 合并后的弹幕总条数
     */
    public function save(int|string $cid, array $items, ?int $videoId = null): int
    {
        // 过滤空内容弹幕
        $items = array_values(array_filter($items, fn ($item) => ! empty($item['content'])));

        $merged   = array_values($this->dedupe(array_merge($this->read($cid), $this->legacyRows($cid), $items)));
        $count    = count($merged);
        $path     = $this->filePath($cid);
        $relative = $this->relativePath($path);

        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            Log::error('[弹幕存储] 弹幕序列化失败', ['cid' => $cid, 'count' => $count]);

            return 0;
        }

        if (! $this->writeAtomic($path, gzencode($json, 6))) {
            Log::error('[弹幕存储] 弹幕文件写入失败', ['cid' => $cid, 'path' => $path]);

            return 0;
        }

        // 写入成功：同步索引（先索引、后清遗留行，任何中断都是安全的）
        $this->touchIndex($cid, $count, $relative, $videoId);

        // 自迁移：数据库遗留行已并入文件，安全清理（仅限该 cid）
        Danmaku::query()->where('cid', $cid)->delete();

        return $count;
    }

    /**
     * 删除指定 cid 的弹幕文件、索引与数据库遗留行。
     */
    public function delete(int|string $cid): void
    {
        $path = $this->filePath($cid);
        if (is_file($path)) {
            @unlink($path);
        }
        DanmakuFile::query()->where('cid', $cid)->delete();
        Danmaku::query()->where('cid', $cid)->delete();
    }

    /**
     * 批量删除（按 cid 列表）。
     *
     * @param  array<int, int|string>  $cids
     */
    public function deleteMany(array $cids): void
    {
        foreach ($cids as $cid) {
            $this->delete($cid);
        }
    }

    /**
     * 文件化弹幕总条数（用于系统统计；数据库遗留行由调用方另行统计）。
     */
    public function totalFileCount(): int
    {
        return (int) DanmakuFile::query()->sum('count');
    }

    /**
     * 按弹幕 id 去重（无 id 的按内容特征去重），后出现的覆盖先出现的。
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function dedupe(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = isset($item['id']) && $item['id'] !== '' && $item['id'] !== null
                ? 'i:' . $item['id']
                : 's:' . md5(($item['content'] ?? '') . '|' . ($item['progress'] ?? '') . '|' . ($item['mode'] ?? ''));
            $result[$key] = [
                'id'       => $item['id'] ?? null,
                'progress' => (int) ($item['progress'] ?? 0),
                'mode'     => (int) ($item['mode'] ?? 1),
                'color'    => (int) ($item['color'] ?? 16777215),
                'content'  => (string) ($item['content'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * 原子写入：先写临时文件再重命名，避免写一半产生损坏文件。
     */
    private function writeAtomic(string $path, string|false $contents): bool
    {
        if ($contents === false) {
            return false;
        }

        $tmp = $path . '.' . uniqid('tmp', true);
        if (@file_put_contents($tmp, $contents) === false) {
            return false;
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * 更新 danmaku_files 索引。
     */
    private function touchIndex(int|string $cid, int $count, string $relativePath, ?int $videoId): void
    {
        DanmakuFile::query()->updateOrCreate(
            ['cid' => $cid],
            [
                'video_id'   => $videoId,
                'count'      => $count,
                'path'       => $relativePath,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * 生成相对 storage/app/public 的路径（供媒体目录与统计使用）。
     */
    private function relativePath(string $absolutePath): string
    {
        $public = storage_path('app/public') . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $public)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($public)));
        }

        return $absolutePath;
    }
}