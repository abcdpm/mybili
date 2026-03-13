<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 为了消除 Filesort，需要针对查询条件和排序规则创建一个新的复合索引。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // 添加完美匹配 Controller 查询条件的复合索引
            $table->index(['video_id', 'root', 'is_top', 'like'], 'idx_video_root_top_like');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('idx_video_root_top_like');
        });
    }
};