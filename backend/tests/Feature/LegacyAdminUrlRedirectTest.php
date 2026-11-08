<?php

// 旧の管理画面の一覧・編集の URL(#626)。旧のブックマークから来た管理者を、新の同じ画面へ 302 で移す。
// 決めたことと旧と挙動を変えたところは docs/decisions/legacy-admin-urls.md。
// 移した先でログインを確かめること(未ログインならログインの画面へ移る)は e2e/tests/legacy-admin-urls.spec.ts

beforeEach(function () {
    $this->withoutVite();
});

it('TC-626-01: 旧の一覧の URL を GET すると、新の一覧へ 302 で移る', function (string $path, string $destination) {
    $response = $this->get($path);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe($destination);
})->with([
    'カテゴリ' => ['/admin/list/Category', '/admin/categories'],
    'ミドル' => ['/admin/list/Middle', '/admin/categories'],
    '銘柄' => ['/admin/list/Brand', '/admin/brands'],
    '用語辞典' => ['/admin/list/Dictionary', '/admin/dictionaries'],
    'カクテル' => ['/admin/list/Cocktail', '/admin/cocktails'],
    'カクテルの下書き' => ['/admin/list/CocktailDraft', '/admin/cocktails?status=draft'],
    '末尾のスラッシュ付き' => ['/admin/list/Brand/', '/admin/brands'],
]);

it('TC-626-02: 旧の新規の URL(ID なし)を GET すると、新の新規の画面へ 302 で移る', function (string $path, string $destination) {
    $response = $this->get($path);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe($destination);
})->with([
    '銘柄' => ['/admin/edit/Brand', '/admin/brands/new'],
    '銘柄(末尾のスラッシュ付き。旧のリンクの形)' => ['/admin/edit/Brand/', '/admin/brands/new'],
    '用語辞典' => ['/admin/edit/Dictionary', '/admin/dictionaries/new'],
    '用語辞典(末尾のスラッシュ付き。旧のリンクの形)' => ['/admin/edit/Dictionary/', '/admin/dictionaries/new'],
    'カクテル' => ['/admin/edit/Cocktail', '/admin/cocktails/new'],
    'カクテル(末尾のスラッシュ付き。旧のリンクの形)' => ['/admin/edit/Cocktail/', '/admin/cocktails/new'],
]);

it('TC-626-03: 旧の編集の URL を GET すると、同じ ID の新の編集画面へ 302 で移る', function (string $path, string $destination) {
    $response = $this->get($path);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe($destination);
})->with([
    '銘柄' => ['/admin/edit/Brand/12', '/admin/brands/12'],
    '用語辞典' => ['/admin/edit/Dictionary/34', '/admin/dictionaries/34'],
    'カクテル' => ['/admin/edit/Cocktail/56', '/admin/cocktails/56'],
]);

it('TC-626-04: 旧の URL に付いてきたクエリは、移った先の URL に持ち越さない', function (string $path, string $destination) {
    $response = $this->get($path);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe($destination);
})->with([
    '一覧' => ['/admin/list/Brand?q=ジン', '/admin/brands'],
    '下書きの一覧(行き先のクエリは残す)' => ['/admin/list/CocktailDraft?status=published', '/admin/cocktails?status=draft'],
    '編集' => ['/admin/edit/Dictionary/34?from=menu', '/admin/dictionaries/34'],
]);

it('TC-626-05: 新の画面がまだ無い・移さないと決めた旧の URL は、移さずに SPA の HTML を返す(画面は 404 の画面)', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'カテゴリの編集' => '/admin/edit/Category/1',
    'カテゴリの新規' => '/admin/edit/Category/',
    'ミドルの編集' => '/admin/edit/Middle/1',
    'ミドルの新規' => '/admin/edit/Middle/',
    '言い換え' => '/admin/autoinput/paraphrase',
    'オートインプット' => '/admin/autoinput/exe',
    'オートインプットの次' => '/admin/autoinput/next',
]);

it('TC-626-06: 旧の URL に似ているが違うパスは、移さずに SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'list だけ' => '/admin/list',
    '大文字小文字違い' => '/admin/list/brand',
    '一覧の下' => '/admin/list/Brand/1',
    '数字でない ID' => '/admin/edit/Brand/abc',
    '負の ID' => '/admin/edit/Dictionary/-1',
    '編集の下' => '/admin/edit/Brand/1/x',
    '旧に無い種類' => '/admin/list/Users',
]);

it('TC-626-07: 旧の URL への GET 以外は移さず 405 を返す', function (string $path) {
    $this->post($path)->assertStatus(405);
})->with([
    '一覧' => '/admin/list/Cocktail',
    '新規' => '/admin/edit/Brand',
    '編集' => '/admin/edit/Dictionary/34',
]);

it('TC-626-08: 新の管理画面の URL は、これまでどおり SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'カテゴリ木' => '/admin/categories',
    '銘柄の一覧' => '/admin/brands',
    '銘柄の新規' => '/admin/brands/new',
    '用語の編集' => '/admin/dictionaries/34',
    'カクテルの下書き' => '/admin/cocktails?status=draft',
]);
