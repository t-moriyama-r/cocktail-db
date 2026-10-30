<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CocktailController;
use App\Http\Controllers\Admin\CocktailFormOptionsController;
use App\Http\Controllers\Admin\CocktailIndexController;
use App\Http\Controllers\Admin\CocktailPreviewController;
use App\Http\Controllers\Admin\CurrentAdminController;
use App\Http\Controllers\Admin\DictionaryController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\ParaphraseController;
use App\Http\Controllers\Admin\PasskeyController;
use App\Http\Controllers\Admin\SkipWordController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\BrandDetailController;
use App\Http\Controllers\CategoryDetailController;
use App\Http\Controllers\CocktailDetailController;
use App\Http\Controllers\CocktailListController;
use App\Http\Controllers\CocktailRelatedController;
use App\Http\Controllers\CocktailSameNameController;
use App\Http\Controllers\CocktailSearchController;
use App\Http\Controllers\DictionaryDetailController;
use App\Http\Controllers\DictionaryIndexController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\LegacySearchConditionController;
use App\Http\Controllers\NameSearchController;
use App\Http\Controllers\RandomCocktailsController;
use App\Http\Controllers\RandomWalkController;
use App\Http\Controllers\SearchFormController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloController::class);

Route::get('/search-form', SearchFormController::class);

Route::get('/cocktails', [CocktailListController::class, 'index']);
Route::get('/cocktails/count', [CocktailListController::class, 'count']);
Route::get('/cocktails/latest-video', [CocktailListController::class, 'latestVideo']);

// 条件検索。選んだノードの ID が数百個になりうるので、GET のクエリではなく JSON の本文で受ける(#163)
Route::post('/cocktails/search', CocktailSearchController::class);

Route::get('/affiliates', AffiliateController::class);

// ランダムウォーク(#45)。ヘッダーの「現在の登録数」のリンク先を、カクテル・ノード・銘柄から 1 件選ぶ
Route::get('/random-walk', RandomWalkController::class);

// 旧サイトが保存した検索条件を直す(#290)。ID が数百になりうるので、GET ではなく本文で受ける
Route::post('/legacy/search-condition', LegacySearchConditionController::class);

// 名前の横断検索(#172)。/cocktails より前に置くと、scramble:export が /cocktails の型を
// 親の CocktailSummaryResource にしてしまい(子の CocktailListItemResource の項目が落ちる)、FE の型がずれる
Route::get('/search', NameSearchController::class);

// ID は int に収まる桁数に縛る(超える数字は経路に合わず 404。TypeError の 500 にしない)
Route::get('/cocktails/{cocktail}', CocktailDetailController::class)->where('cocktail', '[0-9]{1,18}');
Route::get('/cocktails/{cocktail}/same-name', CocktailSameNameController::class)->where('cocktail', '[0-9]{1,18}');

Route::get('/brands/{brand}', BrandDetailController::class)->where('brand', '[0-9]{1,18}');

// 別のカクテル(旧の「ランダムカクテル」。#174)。カクテルを起点にする形と、ノード・銘柄・ベース・技法・グラスを起点にする形
Route::get('/cocktails/{cocktail}/related', CocktailRelatedController::class)->where('cocktail', '[0-9]{1,18}');
Route::get('/cocktails/random', RandomCocktailsController::class);

// ノードの詳細(#42)。旧のカテゴリ・ミドルの詳細を、任意の深さのノードで 1 つにしたもの
Route::get('/categories/{category}', CategoryDetailController::class)->where('category', '[0-9]{1,18}');

Route::get('/dictionaries', DictionaryIndexController::class);
Route::get('/dictionaries/{dictionary}', DictionaryDetailController::class)->where('dictionary', '[0-9]{1,18}');

// パスキーでのログイン(#311)。ログインの前に呼ぶので管理 API の外に置くが、チャレンジをセッションに持つので
// セッションと CSRF の確かめ(web)は通す。署名を何度も試されないよう、IP ごとに 1 分 10 回まで
Route::prefix('auth/passkey')->middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('/login-options', [PasskeyLoginController::class, 'options']);
    Route::post('/login', [PasskeyLoginController::class, 'login']);
});

// 管理 API。ミドルウェアの中身は bootstrap/app.php の admin のグループ
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/me', CurrentAdminController::class);
    Route::get('/cocktails', CocktailIndexController::class);
    Route::post('/logout', LogoutController::class);
    Route::get('/passkeys', [PasskeyController::class, 'index']);
    Route::post('/passkeys/registration-options', [PasskeyController::class, 'registrationOptions']);
    Route::post('/passkeys', [PasskeyController::class, 'store']);
    Route::delete('/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->where('passkey', '[0-9]{1,18}');
    Route::get('/cocktails/{cocktail}/preview', CocktailPreviewController::class)->where('cocktail', '[0-9]{1,18}');

    // カクテルの基本情報(#291)。画像を上げる更新は multipart になるので、POST に _method=PUT を付けて送る
    Route::post('/cocktails', [CocktailController::class, 'store']);
    Route::get('/cocktails/{cocktail}', [CocktailController::class, 'show'])->where('cocktail', '[0-9]{1,18}');
    Route::put('/cocktails/{cocktail}', [CocktailController::class, 'update'])->where('cocktail', '[0-9]{1,18}');
    // カクテルの削除(#130)。材料・別名も消える
    Route::delete('/cocktails/{cocktail}', [CocktailController::class, 'destroy'])->where('cocktail', '[0-9]{1,18}');
    Route::get('/cocktail-form-options', CocktailFormOptionsController::class);
    Route::apiResource('paraphrases', ParaphraseController::class)->where(['paraphrase' => '[0-9]{1,18}']);
    Route::apiResource('skip-words', SkipWordController::class)->except('show')->where(['skip_word' => '[0-9]{1,18}']);

    // 銘柄(#314)。画像を上げる更新は multipart になるので、POST に _method=PUT を付けて送る
    Route::get('/brands', [BrandController::class, 'index']);
    // 確かめ用(#528)。生成ファイルの作り直しを確かめるためだけの試しのルート。develop には入れない
    Route::get('/brands-regen-check', [BrandController::class, 'index']);
    Route::post('/brands', [BrandController::class, 'store']);
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->where('brand', '[0-9]{1,18}');
    Route::put('/brands/{brand}', [BrandController::class, 'update'])->where('brand', '[0-9]{1,18}');

    // 用語辞典(#315)。画像を上げる更新は multipart になるので、POST に _method=PUT を付けて送る
    Route::apiResource('dictionaries', DictionaryController::class)->where(['dictionary' => '[0-9]{1,18}']);
});
