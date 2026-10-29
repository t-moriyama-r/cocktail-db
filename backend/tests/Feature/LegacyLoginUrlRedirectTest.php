<?php

// 旧の Twitter ログインの URL(#117)。旧のブックマークから来た人を、新の管理画面(/admin)へ移す。
// 旧と挙動を変えたところは docs/decisions/legacy-login-urls.md の「旧との差分」。
// ログアウトの URL でセッションが切れないこと(DB を使う)は Auth/LegacyLogoutUrlTest.php。

beforeEach(function () {
    $this->withoutVite();
});

it('TC-117-01: 旧の 3 つの URL を GET すると、/admin へ 302 で移る', function (string $path) {
    $this->get($path)
        ->assertStatus(302)
        ->assertRedirect('/admin');
})->with([
    'ログイン' => '/auth/twitter',
    'コールバック' => '/Cocktail/auth/twitter/callback',
    'ログアウト' => '/auth/logout',
]);

it('TC-117-02: 旧のコールバックに付いてきたクエリ(OAuth のトークン)は、移った先の URL に持ち越さない', function () {
    $response = $this->get('/Cocktail/auth/twitter/callback?oauth_token=abc&oauth_verifier=def');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('/admin');
});

it('TC-117-03: 旧の URL に似ているが違うパスは、これまでどおり SPA の HTML を返す', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="root"></div>', false);
})->with([
    'auth だけ' => '/auth',
    'ログインの下' => '/auth/twitter/x',
    'コールバックの手前' => '/Cocktail/auth/twitter',
    'コールバックの大文字小文字違い' => '/cocktail/auth/twitter/callback',
]);

it('TC-117-04: 旧の URL への GET 以外は転送せず 405 を返す', function (string $path) {
    $this->post($path)->assertStatus(405);
})->with([
    'ログイン' => '/auth/twitter',
    'コールバック' => '/Cocktail/auth/twitter/callback',
    'ログアウト' => '/auth/logout',
]);
