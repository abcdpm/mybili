<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->json('pictures')->nullable()->comment('评论图片列表');
            $table->json('emotes')->nullable()->comment('评论表情包映射');
            $table->boolean('is_top')->default(false)->comment('是否置顶');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['pictures', 'emotes', 'is_top']);
        });
    }
};