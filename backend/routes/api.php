<?php

use App\Http\Controllers\Admin\CurrentAdminController;
use App\Http\Controllers\HelloController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloController::class);

// 管理 API。ミドルウェアの中身は bootstrap/app.php の admin のグループ
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/me', CurrentAdminController::class);
});
