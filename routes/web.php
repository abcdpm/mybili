<?php

use Illuminate\Support\Facades\Route;

// 1. 保留原本的 GET 规则
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');

// 2. 新增 POST 规则：捕获后强制发起 302 重定向到对应的 GET 地址
Route::post('/{any}', function ($any) {
    return redirect('/' . $any);
})->where('any', '.*');