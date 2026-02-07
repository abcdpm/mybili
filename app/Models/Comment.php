<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ctime' => 'datetime',
        'pictures' => 'array', // 自动转换 JSON
        'emotes' => 'array',   // 自动转换 JSON
        'is_top' => 'boolean',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'root', 'rpid')->orderBy('like', 'desc'); // 子评论按点赞排序
    }
}