<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_parts', function (Blueprint $table) {
            // 添加 mobile_download_path 字段，用于存储转码后的文件路径
            $table->string('mobile_download_path')->nullable()->after('video_download_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_parts', function (Blueprint $table) {
            $table->dropColumn('mobile_download_path');
        });
    }
};
