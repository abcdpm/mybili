<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 comments 表的 mid 建立索引。
 *
 * 用途：评论用户归一化回填（app:comments-backfill-users）按 mid 游标分批，
 * 以及评论序列化按 mid 关联 comment_users。此前无索引时任何按 mid 的查询
 * 均为全表扫描（839 万行）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->index('mid');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['mid']);
        });
    }
};