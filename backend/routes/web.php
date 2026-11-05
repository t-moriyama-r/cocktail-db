<?php

use App\Http\Controllers\AdminLoginLinkController;
use App\Http\Controllers\DetailPageController;
use App\Http\Controllers\LegacyCardUrlController;
use App\Http\Controllers\LegacyCategoryUrlController;
use App\Http\Controllers\SitemapController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

// 非常用のログイン URL(php artisan admin:login-link)。下の SPA へ渡すルートが /admin 以下も拾うので、その前に置く
Route::get('/admin/login-link/{user}', AdminLoginLinkController::class)
    ->whereNumber('user')
    ->middleware('signed')
    ->name('admin.login-link');

// 旧の Twitter ログインの URL(ログイン・コールバック・ログアウト)は、ブックマークから来た人を管理画面へ移す(#117)。
// 旧と同じく GET だけを受ける(Route::redirect はすべてのメソッドを受けるので使わない)。クエリは持ち越さない。
// 旧と変えたところは docs/decisions/legacy-login-urls.md の「旧との差分」
foreach (['auth/twitter', 'Cocktail/auth/twitter/callback', 'auth/logout'] as $legacyLoginUrl) {
    Route::get($legacyLoginUrl, RedirectController::class)
        ->defaults('destination', '/admin')
        ->defaults('status', 302);
}

// 旧のカテゴリ・ミドルの詳細は、移した先のノードの /category/{新ID} へ 301 で移す。対応表に無い ID は 404(#78)。
// 数字でない ID も 404 にするので、ID の形で絞らない。旧の管理画面の URL(/Cocktail/admin 以下)は /admin へ 301 で移す。
// どちらも GET だけを受け、クエリは持ち越さない。旧と変えたところは docs/decisions/legacy-urls.md の「旧との差分」。
// カクテルと銘柄は ID が変わらないので転送しない
Route::get('/detail/category/{id}', [LegacyCategoryUrlController::class, 'category']);
Route::get('/detail/middle/{id}', [LegacyCategoryUrlController::class, 'middle']);
Route::get('/Cocktail/admin/{any?}', RedirectController::class)
    ->where('any', '.*')
    ->defaults('destination', '/admin')
    ->defaults('status', 301);

// 旧の SNS カードの URL(/card/{command}/{id})は、同じものの詳細ページへ 301 で移す。カテゴリ・ミドルは上と同じ付け替え(#113)。
// 知らない command・無い ID・数字でない ID は 404 にするので、形で絞らない。GET だけを受け、クエリは持ち越さない
Route::get('/card/{command}/{id}', LegacyCardUrlController::class);

// 詳細ページは、SPA の HTML にページごとの title・description・canonical を入れ、無い ID・下書きは 404 で返す(#111)。
// 数字でない ID は下の SPA へ渡すルートに任せ、画面が 404 の画面を出す
Route::get('/detail/cocktail/{id}', [DetailPageController::class, 'cocktail'])->whereNumber('id');
Route::get('/category/{id}', [DetailPageController::class, 'category'])->whereNumber('id');
Route::get('/detail/brand/{id}', [DetailPageController::class, 'brand'])->whereNumber('id');
Route::get('/dictionary/{id}', [DetailPageController::class, 'dictionary'])->whereNumber('id');

// sitemap.xml と robots.txt(#114)。下の SPA へ渡すルートが拾うので、その前に置く。
// robots.txt は sitemap.xml の場所を APP_URL で書くため、public/ の静的なファイルにせずここで返す
Route::get('/sitemap.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-{page}.xml', [SitemapController::class, 'page'])->where('page', '[1-9][0-9]*');
Route::get('/robots.txt', [SitemapController::class, 'robots']);

// API 以外のパスはすべて SPA に渡し、画面の出し分けは React 側のルーターに任せる
Route::view('/{any?}', 'spa')->where('any', '^(?!api(/|$)|up$).*');
