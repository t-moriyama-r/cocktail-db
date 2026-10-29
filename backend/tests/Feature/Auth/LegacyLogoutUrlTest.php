<?php

use App\Models\User;
use App\Services\AdminLoginLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 旧のログアウトの URL(/auth/logout。#117)は /admin へ移すだけで、ログアウトはしない。
// 旧はログアウトしてから / へ移していた(docs/decisions/legacy-login-urls.md の「旧との差分」)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();
});

it('TC-117-05: 旧のログアウトの URL を開いても、ログインしたセッションは切れない', function () {
    $admin = User::factory()->create();
    $login = $this->get(app(AdminLoginLinks::class)->issue($admin))->assertRedirect('/admin');
    $cookieName = config()->string('session.cookie');
    $session = $login->getCookie($cookieName)?->getValue() ?? throw new RuntimeException('セッションのクッキーが無い');

    $this->app['auth']->forgetGuards();
    $this->withCookie($cookieName, $session)->get('/auth/logout')->assertRedirect('/admin');

    // 次のリクエストでは、ログインの状態をクッキーのセッションからだけ読ませる
    $this->app['auth']->forgetGuards();
    $this->withCookie($cookieName, $session)
        ->getJson('/api/admin/me')
        ->assertOk()
        ->assertJsonPath('id', $admin->id);
});
