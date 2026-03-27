<?php

use App\Http\Controllers\CookieController;
use App\Http\Controllers\DownloadQueueController;
use App\Http\Controllers\FavController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\ImageProxyController;

Route::apiResource('/fav', FavController::class)->only(['show', 'index']);
Route::post('/fav/reorder', [FavController::class, 'reorder']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::get('/videos', [VideoController::class, 'index']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
Route::get('/danmaku', [VideoController::class, 'danmaku']);
Route::get('/progress', [VideoController::class, 'progress']);
Route::get('/cookie/exist', [CookieController::class, 'checkFileExist']);
Route::get('/cookie/status', [CookieController::class, 'checkCookieValid']);
Route::post('/cookie/upload', [CookieController::class, 'uploadCookieFile']);
Route::get('/settings', [SettingsController::class, 'getSettings']);
Route::post('/settings', [SettingsController::class, 'saveSettings']);
Route::post('/settings/test-telegram', [SettingsController::class, 'testTelegramConnection']);

Route::apiResource('/subscription', SubscriptionController::class)->only(['index', 'store', 'update', 'destroy', 'show']);

// 显示系统校准信息
Route::get('/system/info', [SystemController::class, 'getSystemInfo']);
// 系统日志接口
Route::get('/system/logs', [SystemController::class, 'logs']);
// 系统日志清空接口
Route::post('/system/logs/clear', [SystemController::class, 'clearLogs']);
// 系统运维：队列积压查询
Route::get('/system/queue-stats', [SystemController::class, 'queueStats']);

// 获取视频评论
Route::get('/videos/{id}/comments', [VideoController::class, 'comments']);
// 获取指定主评论下的追加子评论
Route::get('/comments/{rootId}/replies', [VideoController::class, 'replies']);
// 获取视频标签的路由
Route::get('/videos/{id}/tags', [VideoController::class, 'tags']);
// 图片智能本地化代理接口
Route::get('/image/proxy', [ImageProxyController::class, 'proxy']);

// 手动更新路由
Route::post('/videos/{id}/update-danmaku', [VideoController::class, 'updateDanmaku']);
Route::post('/videos/{id}/update-comments', [VideoController::class, 'updateComments']);
Route::post('/videos/{id}/update-stats', [VideoController::class, 'updateStats']);

// 下载队列管理
Route::get('/download-queue', [DownloadQueueController::class, 'index']);
Route::get('/download-queue/stat', [DownloadQueueController::class, 'stat']);
Route::post('/download-queue/{id}/cancel', [DownloadQueueController::class, 'cancel']);
Route::post('/download-queue/{id}/retry', [DownloadQueueController::class, 'retry']);
Route::post('/download-queue/{id}/priority', [DownloadQueueController::class, 'priority']);
