<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->unsignedBigInteger('rpid')->unique()->comment('B站评论ID');
            $table->unsignedBigInteger('oid')->comment('B站对象ID(AV号/稿件ID)');
            $table->unsignedBigInteger('mid')->comment('用户ID');
            $table->string('uname')->comment('用户名');
            $table->string('avatar')->comment('头像URL');
            $table->text('content')->comment('评论内容');
            $table->integer('like')->default(0)->comment('点赞数');
            $table->unsignedBigInteger('root')->default(0)->comment('根评论ID, 0为一级评论');
            $table->unsignedBigInteger('parent')->default(0)->comment('回复的父评论ID');
            $table->timestamp('ctime')->nullable()->comment('评论时间');
            $table->timestamps();
            
            // 索引优化查询
            $table->index(['video_id', 'root', 'like']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};