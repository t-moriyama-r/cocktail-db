<?php

use App\Http\Controllers\AdminLoginLinkController;
use Illuminate\Support\Facades\Route;

// 非常用のログイン URL(php artisan admin:login-link)。下の SPA へ渡すルートが /admin 以下も拾うので、その前に置く
Route::get('/admin/login-link/{user}', AdminLoginLinkController::class)
    ->whereNumber('user')
    ->middleware('signed')
    ->name('admin.login-link');

// API 以外のパスはすべて SPA に渡し、画面の出し分けは React 側のルーターに任せる
Route::view('/{any?}', 'spa')->where('any', '^(?!api(/|$)|up$).*');
