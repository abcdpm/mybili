<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 评论区用户信息（归一化存储，替代 comments 行内冗余的 uname/avatar）。
 */
class CommentUser extends Model
{
    protected $table = 'comment_users';

    protected $primaryKey = 'mid';

    public $incrementing = false;

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['mid', 'uname', 'avatar', 'updated_at'];

    protected $casts = [
        'mid' => 'integer',
    ];
}