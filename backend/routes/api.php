<?php

use App\Http\Controllers\Admin\CocktailPreviewController;
use App\Http\Controllers\Admin\CurrentAdminController;
use App\Http\Controllers\CocktailDetailController;
use App\Http\Controllers\CocktailSameNameController;
use App\Http\Controllers\CocktailSearchController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\SearchFormController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloController::class);

Route::get('/search-form', SearchFormController::class);

// 条件検索。選んだノードの ID が数百個になりうるので、GET のクエリではなく JSON の本文で受ける(#163)
Route::post('/cocktails/search', CocktailSearchController::class);

// ID は int に収まる桁数に縛る(超える数字は経路に合わず 404。TypeError の 500 にしない)
Route::get('/cocktails/{cocktail}', CocktailDetailController::class)->where('cocktail', '[0-9]{1,18}');
Route::get('/cocktails/{cocktail}/same-name', CocktailSameNameController::class)->where('cocktail', '[0-9]{1,18}');

// 管理 API。ミドルウェアの中身は bootstrap/app.php の admin のグループ
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/me', CurrentAdminController::class);
    Route::get('/cocktails/{cocktail}/preview', CocktailPreviewController::class)->where('cocktail', '[0-9]{1,18}');
});
