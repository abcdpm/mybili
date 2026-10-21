<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 评论用户信息归一化表。
 *
 * 背景：comments 表此前在每条评论上冗余存储 uname/avatar
 * （839 万条评论 ≈ 每行 80-150 字节重复数据），是数据库膨胀主因之一。
 * 归一化后按 mid 一行存储用户信息，评论行只保留 mid。
 *
 * 兼容性：本迁移只做加法，不影响既有 comments 数据；
 * 新写入评论同步落表，历史数据可用 app:comments-backfill-users 回填，
 * 空间回收（清空行内冗余列）请使用 app:comments-reclaim-space（opt-in）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_users', function (Blueprint $table) {
            $table->unsignedBigInteger('mid')->primary()->comment('B站用户ID');
            $table->string('uname')->default('')->comment('用户名');
            $table->string('avatar')->default('')->comment('头像（相对路径或远程URL）');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_users');
    }
};