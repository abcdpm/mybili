<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emotes', function (Blueprint $table) {
            $table->id();
            $table->string('url_hash')->unique()->comment('远程URL的MD5 hash，用于去重');
            $table->string('filename')->comment('本地文件名');
            $table->string('text')->nullable()->comment('表情包文字，如 [doge]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emotes');
    }
};