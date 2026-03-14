<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 子评论索引
return new class extends Migration
{
    public function up()
    {
        Schema::table('comments', function (Blueprint $table) {
            // 只需要专门针对子评论的获取和数量统计创建新索引
            $table->index(['root', 'like'], 'idx_root_like');
        });
    }

    public function down()
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('idx_root_like');
        });
    }
};