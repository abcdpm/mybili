<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 在 favorite_lists 表中增加一个 sort_order 字段来记录收藏夹排序位置
return new class extends Migration
{
    public function up()
    {
        Schema::table('favorite_lists', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('id');
        });
    }

    public function down()
    {
        Schema::table('favorite_lists', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};