<?php

use App\Http\Controllers\Admin\CocktailPreviewController;
use App\Http\Controllers\Admin\CurrentAdminController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\ParaphraseController;
use App\Http\Controllers\Admin\PasskeyController;
use App\Http\Controllers\Admin\SkipWordController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\CocktailDetailController;
use App\Http\Controllers\CocktailSameNameController;
use App\Http\Controllers\CocktailSearchController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\LegacySearchConditionController;
use App\Http\Controllers\SearchFormController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloController::class);

Route::get('/search-form', SearchFormController::class);

// 条件検索。選んだノードの ID が数百個になりうるので、GET のクエリではなく JSON の本文で受ける(#163)
Route::post('/cocktails/search', CocktailSearchController::class);

// 旧サイトが保存した検索条件を直す(#290)。ID が数百になりうるので、GET ではなく本文で受ける
Route::post('/legacy/search-condition', LegacySearchConditionController::class);

// ID は int に収まる桁数に縛る(超える数字は経路に合わず 404。TypeError の 500 にしない)
Route::get('/cocktails/{cocktail}', CocktailDetailController::class)->where('cocktail', '[0-9]{1,18}');
Route::get('/cocktails/{cocktail}/same-name', CocktailSameNameController::class)->where('cocktail', '[0-9]{1,18}');

// パスキーでのログイン(#311)。ログインの前に呼ぶので管理 API の外に置くが、チャレンジをセッションに持つので
// セッションと CSRF の確かめ(web)は通す。署名を何度も試されないよう、IP ごとに 1 分 10 回まで
Route::prefix('auth/passkey')->middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('/login-options', [PasskeyLoginController::class, 'options']);
    Route::post('/login', [PasskeyLoginController::class, 'login']);
});

// 管理 API。ミドルウェアの中身は bootstrap/app.php の admin のグループ
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/me', CurrentAdminController::class);
    Route::post('/logout', LogoutController::class);
    Route::get('/passkeys', [PasskeyController::class, 'index']);
    Route::post('/passkeys/registration-options', [PasskeyController::class, 'registrationOptions']);
    Route::post('/passkeys', [PasskeyController::class, 'store']);
    Route::delete('/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->where('passkey', '[0-9]{1,18}');
    Route::get('/cocktails/{cocktail}/preview', CocktailPreviewController::class)->where('cocktail', '[0-9]{1,18}');
    Route::apiResource('paraphrases', ParaphraseController::class)->where(['paraphrase' => '[0-9]{1,18}']);
    Route::apiResource('skip-words', SkipWordController::class)->except('show')->where(['skip_word' => '[0-9]{1,18}']);
});
