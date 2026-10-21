<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 弹幕文件化存储的元数据索引表。
 *
 * 背景：弹幕迁出数据库后按 cid 存储为 JSON.gz 单文件，
 * 此表仅保留索引信息（cid → 文件内弹幕条数），供统计与清理使用，
 * 不承载弹幕正文本身。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('danmaku_files', function (Blueprint $table) {
            $table->unsignedBigInteger('cid')->primary()->comment('B站分P CID');
            $table->unsignedBigInteger('video_id')->nullable()->comment('关联 videos.id，便于级联清理');
            $table->unsignedInteger('count')->default(0)->comment('文件内弹幕条数');
            $table->string('path')->comment('相对 storage/app/public 的文件路径');
            $table->timestamp('updated_at')->nullable();

            $table->index('video_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('danmaku_files');
    }
};