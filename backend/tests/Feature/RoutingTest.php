<?php

// SPA へ渡すかどうかは routes/web.php の正規表現 ^(?!api(/|$)|up$).* で決まる。
// 「api」「up」で始まるパスの境界で取り違えると、API が HTML を返したり画面が 404 になったりする。

beforeEach(function () {
    $this->withoutVite();
});

it('TC-6-02: GET /up がヘルスチェックとして 200 を返す', function () {
    $this->get('/up')->assertOk();
});

it('TC-6-03: 画面のパスでは SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'トップ' => '/',
    // 数字の ID の詳細は DB を引いて title などを入れる(#111。DetailPageHeadTest)。ここでは DB を使わないパスを見る
    '数字でない ID のカクテルの詳細' => '/detail/cocktail/abc',
    '深い階層' => '/category/1/children/2',
]);

it('TC-111-16: 数字でない ID の詳細は、DB を引かずに SPA の HTML を 200 で返す(画面が 404 の画面を出す)', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false)
        ->assertSee('<title>宅飲みカクテルデータベース</title>', false);
})->with([
    'カクテル' => '/detail/cocktail/abc',
    'カテゴリ' => '/category/1a',
    '銘柄' => '/detail/brand/-1',
    '用語' => '/dictionary/abc',
]);

it('TC-111-17: 詳細でないページの HTML は今のまま(title はサイト名で、description・canonical を入れない)', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<title>宅飲みカクテルデータベース</title>', false)
        ->assertDontSee('name="description"', false)
        ->assertDontSee('rel="canonical"', false);
})->with([
    'トップ' => '/',
    '用語辞典' => '/dictionary',
    '条件検索の結果' => '/result/Cocktails',
    '知らない URL' => '/nope',
]);

it('TC-6-04: 「api」で始まっても api/ でなければ SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'apis' => '/apis',
    'api-docs' => '/api-docs',
]);

it('TC-6-05: 「up」で始まっても up そのものでなければ SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'upx' => '/upx',
    'up の下' => '/up/x',
]);

it('TC-115-18: 非常用のログイン URL 以外の /admin 以下は、これまでどおり SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    '管理画面' => '/admin',
    '管理画面の下' => '/admin/cocktails',
    'ユーザーの無い login-link' => '/admin/login-link',
    'ユーザーが数字でない login-link' => '/admin/login-link/abc',
]);

it('TC-6-07:api そのもの・api/ の下の存在しないパスは SPA を返さず 404 になる', function (string $path) {
    $this->get($path)
        ->assertNotFound()
        ->assertDontSee('<div id="root"></div>', false);
})->with([
    'api' => '/api',
    'api/' => '/api/',
    '存在しない API' => '/api/unknown',
]);

it('TC-6-08: api/ の下の存在しないパスは JSON で 404 を返す', function () {
    $this->get('/api/unknown')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});
