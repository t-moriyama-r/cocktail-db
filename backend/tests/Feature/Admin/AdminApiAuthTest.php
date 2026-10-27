<?php

use App\Models\User;
use App\Services\AdminLoginLinks;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

// 管理 API(/api/admin/*。#118)の認可と CSRF。
// 管理画面はログイン(#115 の非常用 URL・パスキー)で作ったセッションで API を呼ぶので、管理 API だけセッションと
// CSRF の確かめを通す。公開の API(/api/*)はセッションを使わないままにする。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();

    // テストの中では CSRF の確かめが既定で飛ばされるので、本番と同じように確かめさせる
    $this->app->bind(PreventRequestForgery::class, CsrfCheckedInTests::class);

    // 書き込みの管理 API はまだ無いので、管理 API と同じ形で書き込みのルートを置いて確かめる
    Route::middleware(['api', 'admin'])->post('api/admin/__probe', fn () => ['ok' => true]);
});

class CsrfCheckedInTests extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

/**
 * 画面(SPA)を開いたときと同じように、セッションの ID と XSRF-TOKEN のクッキーを受け取る。
 *
 * @return array{session: string, xsrf: string}
 */
function openAdminPage(TestCase $test): array
{
    $page = $test->get('/admin')->assertOk();

    return [
        'session' => sessionCookie($page),
        // ブラウザの JS が読むのは暗号化されたままのクッキーの値なので、復号せずに取る
        'xsrf' => $page->getCookie('XSRF-TOKEN', decrypt: false)?->getValue() ?? throw new RuntimeException('XSRF-TOKEN が無い'),
    ];
}

/**
 * 返事が付けたセッションのクッキー(復号した値)を返す。
 *
 * @param  TestResponse<Response>  $response
 */
function sessionCookie(TestResponse $response): string
{
    return $response->getCookie(config()->string('session.cookie'))?->getValue()
        ?? throw new RuntimeException('セッションのクッキーが無い');
}

it('TC-118-01: ログインしていないと、管理 API は 401 を JSON で返す', function () {
    $this->getJson('/api/admin/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('TC-118-02: JSON を求めないリクエスト(ブラウザで直に開く)でも、ログイン画面へ移さず 401 を JSON で返す', function () {
    $this->get('/api/admin/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('TC-118-03: 非常用のログイン URL でログインしたセッションで、管理 API を呼べる', function () {
    $admin = User::factory()->create();
    $login = $this->get(app(AdminLoginLinks::class)->issue($admin))->assertRedirect('/admin');

    // 次のリクエストでは、ログインの状態をクッキーのセッションからだけ読ませる
    $this->app['auth']->forgetGuards();

    $this->withCookie(config()->string('session.cookie'), sessionCookie($login))
        ->getJson('/api/admin/me')
        ->assertOk()
        ->assertExactJson(['id' => $admin->id, 'name' => $admin->name]);
});

it('TC-118-04: ログインしていれば、読み取り(GET)は CSRF のトークン無しで通る', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->getJson('/api/admin/me')->assertOk();
});

it('TC-118-05: ログインしていても、CSRF のトークン無しの書き込みは 419', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/admin/__probe')
        ->assertStatus(419)
        ->assertJson(['message' => 'CSRF token mismatch.']);
});

it('TC-118-06: ログインしていても、セッションと違うトークンの書き込みは 419', function (string $header) {
    $this->actingAs(User::factory()->create());
    $cookies = openAdminPage($this);

    $this->withCookie(config()->string('session.cookie'), $cookies['session'])
        ->withHeader($header, 'not-the-session-token')
        ->postJson('/api/admin/__probe')
        ->assertStatus(419);
})->with(['X-XSRF-TOKEN', 'X-CSRF-TOKEN']);

it('TC-118-07: ログインして、画面の XSRF-TOKEN のクッキーを X-XSRF-TOKEN で送った書き込みは通る', function () {
    $this->actingAs(User::factory()->create());
    $cookies = openAdminPage($this);

    $this->withCookie(config()->string('session.cookie'), $cookies['session'])
        ->withHeader('X-XSRF-TOKEN', $cookies['xsrf'])
        ->postJson('/api/admin/__probe')
        ->assertOk()
        ->assertExactJson(['ok' => true]);
});

it('TC-118-08: ログインしていなければ、CSRF のトークンの無い書き込みでも 419 ではなく 401', function () {
    // 管理画面は 401 を「ログインし直す」合図にするので、セッションが切れてトークンも古くなった書き込みも 401 にそろえる
    $this->postJson('/api/admin/__probe')->assertUnauthorized();
});

it('TC-118-09: ログインしていなければ、正しいトークンの書き込みでも 401', function () {
    $cookies = openAdminPage($this);

    $this->withCookie(config()->string('session.cookie'), $cookies['session'])
        ->withHeader('X-XSRF-TOKEN', $cookies['xsrf'])
        ->postJson('/api/admin/__probe')
        ->assertUnauthorized();
});

it('TC-118-10: トークン無しの書き込みは、ブラウザが同じオリジンからと示したときだけ通る', function (string $secFetchSite, int $status) {
    $this->actingAs(User::factory()->create())
        ->withHeader('Sec-Fetch-Site', $secFetchSite)
        ->postJson('/api/admin/__probe')
        ->assertStatus($status);
})->with([
    '同じオリジン' => ['same-origin', 200],
    '同じサイトの別のオリジン' => ['same-site', 419],
    '別のサイト' => ['cross-site', 419],
]);

it('TC-118-11: /api/admin/ の下のルートは、すべて管理 API のミドルウェアを通る', function () {
    $adminRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/admin/'));

    $withoutAdminMiddleware = $adminRoutes
        ->reject(fn (RouteDefinition $route) => in_array('admin', $route->gatherMiddleware(), true))
        ->map(fn (RouteDefinition $route) => $route->uri())
        ->values()
        ->all();

    expect($adminRoutes->map(fn (RouteDefinition $route) => $route->uri()))->toContain('api/admin/me')
        ->and($withoutAdminMiddleware)->toBe([]);
});

it('TC-118-12: 公開の API は、セッションのクッキーも XSRF-TOKEN も出さない', function () {
    $response = $this->getJson('/api/hello')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});
