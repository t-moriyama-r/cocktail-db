<?php

use App\Http\Controllers\Admin\CocktailIndexController;
use App\Http\Controllers\Admin\CurrentAdminController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\SearchFormController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloController::class);

Route::get('/search-form', SearchFormController::class);

// 管理 API。ミドルウェアの中身は bootstrap/app.php の admin のグループ
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/me', CurrentAdminController::class);
    Route::get('/cocktails', CocktailIndexController::class);
});
