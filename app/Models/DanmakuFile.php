<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 弹幕文件存储的元数据索引（弹幕正文已迁出数据库，按 cid 存 JSON.gz 文件）。
 */
class DanmakuFile extends Model
{
    protected $table = 'danmaku_files';

    protected $primaryKey = 'cid';

    public $incrementing = false;

    // 本表无 created_at 列，仅维护 updated_at
    const CREATED_AT = null;

    protected $fillable = ['cid', 'video_id', 'count', 'path', 'updated_at'];

    protected $casts = [
        'cid' => 'integer',
        'video_id' => 'integer',
        'count' => 'integer',
        'updated_at' => 'datetime',
    ];
}