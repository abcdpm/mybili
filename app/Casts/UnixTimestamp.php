<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 时间字段统一 Cast：数据库存 'Y-m-d H:i:s'，对外读写均为 Unix 秒级时间戳。
 *
 * 取代旧版 Video 模型中「放弃 cast + strtotime 访问器」的散乱实现，
 * 数据层约定归一：
 *   - 读取：DB datetime 字符串 → int 时间戳（与旧版访问器输出完全一致，前端无感）；
 *   - 写入：兼容 int 时间戳 / 'Y-m-d H:i:s' 字符串 / Carbon / null，
 *     其中 'Y-m-d H:i:s' 字符串原样透传，保证 upsert/fill 等批量写入路径行为不变。
 */
class UnixTimestamp implements CastsAttributes
{
    /**
     * 读取：'Y-m-d H:i:s' → Unix 时间戳（与旧版访问器行为逐字一致，含空值处理）。
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value ? strtotime((string) $value) : null;
    }

    /**
     * 写入：统一转换为 'Y-m-d H:i:s' 存储；字符串原样透传。
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon || $value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return (string) $value;
    }
}