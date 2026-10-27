<?php

use App\Http\Controllers\Admin\CocktailPreviewController;
use App\Http\Controllers\Admin\CurrentAdminController;
use App\Http\Controllers\CocktailDetailController;
use App\Http\Controllers\CocktailSameNameController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\SearchFormController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloController::class);

Route::get('/search-form', SearchFormController::class);

Route::get('/cocktails/{cocktail}', CocktailDetailController::class)->whereNumber('cocktail');
Route::get('/cocktails/{cocktail}/same-name', CocktailSameNameController::class)->whereNumber('cocktail');

// 管理 API。ミドルウェアの中身は bootstrap/app.php の admin のグループ
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/me', CurrentAdminController::class);
    Route::get('/cocktails/{cocktail}/preview', CocktailPreviewController::class)->whereNumber('cocktail');
});
