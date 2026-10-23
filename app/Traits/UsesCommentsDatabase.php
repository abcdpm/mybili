<?php

namespace App\Traits;

/**
 * 评论库连接路由（批F·架构拆分）。
 *
 * 评论三表（comments/comment_users/emotes）的 Eloquent 模型使用本 trait：
 * 拆分激活时走 sqlite_comments 连接，否则回落默认连接（主库）。
 * 跨连接关系（Video hasMany Comment）由 Eloquent 以"按关联模型连接查询 + PHP 侧匹配"处理，兼容两种模式。
 */
trait UsesCommentsDatabase
{
    public function getConnectionName()
    {
        return comments_db_split_active() ? 'sqlite_comments' : parent::getConnectionName();
    }
}
