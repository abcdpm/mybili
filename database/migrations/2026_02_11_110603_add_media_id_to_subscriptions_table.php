<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // 添加 media_id 用于存储收藏夹 ID
            $table->string('media_id')->nullable()->after('season_id')->index()->comment('收藏夹ID');
            
            // 如果表中还没有 type 字段，建议加上，方便区分 'upper', 'season', 'favorite'
            if (!Schema::hasColumn('subscriptions', 'type')) {
                $table->string('type')->default('upper')->after('id')->comment('订阅类型: upper, season, favorite');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('media_id');
            if (Schema::hasColumn('subscriptions', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};