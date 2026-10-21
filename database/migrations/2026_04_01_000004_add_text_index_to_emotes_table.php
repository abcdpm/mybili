<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * emotes 表按表情文本（如 [doge]）建立索引。
 *
 * 用途：评论的表情包映射归一化为 key 列表后，
 * 展示时需要按 text 反查本地表情文件，该索引避免大表扫描。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emotes', function (Blueprint $table) {
            $table->index('text');
        });
    }

    public function down(): void
    {
        Schema::table('emotes', function (Blueprint $table) {
            $table->dropIndex(['text']);
        });
    }
};