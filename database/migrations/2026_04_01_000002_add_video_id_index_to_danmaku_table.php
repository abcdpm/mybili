<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 danmaku 表补充 video_id 索引。
 *
 * 背景：删除视频时需要按 video_id 清理弹幕历史数据，
 * 此前仅有 cid 索引，导致每次删除视频都会全表扫描（近千万行）。
 * 注意：大表（近千万行）首次构建索引可能需要数十秒，请耐心等待迁移完成。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('danmaku', function (Blueprint $table) {
            $table->index('video_id');
        });
    }

    public function down(): void
    {
        Schema::table('danmaku', function (Blueprint $table) {
            $table->dropIndex(['video_id']);
        });
    }
};