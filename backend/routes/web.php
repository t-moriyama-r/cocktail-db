<?php

use App\Http\Controllers\AdminLoginLinkController;
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

// API 以外のパスはすべて SPA に渡し、画面の出し分けは React 側のルーターに任せる
Route::view('/{any?}', 'spa')->where('any', '^(?!api(/|$)|up$).*');
