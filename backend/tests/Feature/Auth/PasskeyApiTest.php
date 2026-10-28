<?php

use App\Models\User;
use App\Services\AdminLoginLinks;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Passkey;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

// パスキーの API(#311)。登録・ログイン・一覧・削除・ログアウト。旧は X でのログインだけで、パスキーは旧に無い。
// ブラウザと認証器だけを SoftwareAuthenticator に置き換え、BE には本物と同じ形の応答を送る。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();
});

/**
 * ブラウザが clientDataJSON に入れる、画面のオリジン。BE が受け付けるのは APP_URL のオリジン。
 */
function passkeyOrigin(): string
{
    return config()->string('app.url');
}

/**
 * ログインしているユーザーで、登録の開始から完了までを流す。
 *
 * @return TestResponse<Response>
 */
function registerPasskeyVia(TestCase $test, SoftwareAuthenticator $authenticator, string $name = '仕事の MacBook'): TestResponse
{
    $options = $test->postJson('/api/admin/passkeys/registration-options')->assertOk()->json('options');

    return $test->postJson('/api/admin/passkeys', [
        'name' => $name,
        'credential' => $authenticator->register($options, passkeyOrigin()),
    ]);
}

/**
 * ユーザーを登録したパスキーを 1 つ持たせ、ログインしていない状態に戻す。
 */
function userWithPasskey(TestCase $test, SoftwareAuthenticator $authenticator): User
{
    $user = User::factory()->create();
    $test->actingAs($user);
    registerPasskeyVia($test, $authenticator)->assertCreated();
    logoutForTest();

    return $user;
}

/**
 * テストの中のログインを外す(次のリクエストは、ログインしていない人として送る)。
 */
function logoutForTest(): void
{
    Auth::guard('web')->logout();
    Auth::forgetGuards();
}

/**
 * ログインの開始から完了までを流す。$answer で、ブラウザが返す応答を書き換えられる。
 *
 * @param  (Closure(array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle: string}}): array<string, mixed>)|null  $answer
 * @return TestResponse<Response>
 */
function loginWithPasskey(TestCase $test, SoftwareAuthenticator $authenticator, User $user, ?string $origin = null, ?Closure $answer = null): TestResponse
{
    $options = $test->postJson('/api/auth/passkey/login-options')->assertOk()->json('options');
    $credential = $authenticator->login($options, $origin ?? passkeyOrigin(), $user->getPasskeyUserHandle());

    return $test->postJson('/api/auth/passkey/login', ['credential' => $answer ? $answer($credential) : $credential]);
}

it('TC-311-01: 登録したパスキーでログインでき、その後の管理 API が通り、最後に使った日時が残る', function () {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    $this->getJson('/api/admin/me')->assertUnauthorized();
    $this->travelTo(now()->addDay()->startOfSecond());

    loginWithPasskey($this, $authenticator, $admin)
        ->assertOk()
        ->assertExactJson(['id' => $admin->id, 'name' => $admin->name]);

    $this->assertAuthenticatedAs($admin);
    $this->getJson('/api/admin/me')->assertOk()->assertExactJson(['id' => $admin->id, 'name' => $admin->name]);
    expect(Passkey::query()->sole()->last_used_at?->toJSON())->toBe(now()->toJSON());
});

it('TC-311-02: 登録していないパスキーでは、ログインできず 422', function () {
    $admin = userWithPasskey($this, new SoftwareAuthenticator);

    loginWithPasskey($this, new SoftwareAuthenticator, $admin)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['credential']);

    $this->assertGuest();
});

it('TC-311-03: 消したパスキーでは、ログインできず 422', function () {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    $this->actingAs($admin)->deleteJson('/api/admin/passkeys/'.Passkey::query()->sole()->id)->assertNoContent();
    logoutForTest();

    loginWithPasskey($this, $authenticator, $admin)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['credential']);

    $this->assertGuest();
});

it('TC-311-04: 一度ログインに使った応答を、もう一度送っても通らない(422)', function () {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    $sent = null;
    loginWithPasskey($this, $authenticator, $admin, answer: function (array $credential) use (&$sent) {
        return $sent = $credential;
    })->assertOk();
    logoutForTest();

    $this->postJson('/api/auth/passkey/login', ['credential' => $sent])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['credential']);
    $this->assertGuest();
});

it('TC-311-05: 応答を確かめられないものは 422 で、ログインしない', function (string $case) {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);

    $response = match ($case) {
        // フィッシングのサイトで押させた応答(ブラウザは開いているサイトのオリジンを入れる)
        '別のオリジン' => loginWithPasskey($this, $authenticator, $admin, origin: 'https://evil.example'),
        // 署名を、形の壊れたバイト列に書き換えた応答
        '署名の改ざん' => loginWithPasskey($this, $authenticator, $admin, answer: function (array $credential) {
            $credential['response']['signature'] = SoftwareAuthenticator::b64(random_bytes(70));

            return $credential;
        }),
        // 署名した後で、認証器のデータ(署名の回数)を書き換えた応答。署名の形は正しいが、中身と合わない
        '署名の後の書き換え' => loginWithPasskey($this, $authenticator, $admin, answer: function (array $credential) {
            $data = base64_decode(strtr($credential['response']['authenticatorData'], '-_', '+/'));
            $data[strlen($data) - 1] = chr(ord($data[strlen($data) - 1]) ^ 0x01);
            $credential['response']['authenticatorData'] = SoftwareAuthenticator::b64($data);

            return $credential;
        }),
        // 別の開始のチャレンジに答えた応答(開始を 2 回呼び、古い方に答える)
        '古いチャレンジ' => (function () use ($authenticator, $admin) {
            $old = $this->postJson('/api/auth/passkey/login-options')->json('options');
            $this->postJson('/api/auth/passkey/login-options')->assertOk();

            return $this->postJson('/api/auth/passkey/login', [
                'credential' => $authenticator->login($old, passkeyOrigin(), $admin->getPasskeyUserHandle()),
            ]);
        })(),
        default => throw new LogicException("知らないケース: {$case}"),
    };

    $response->assertUnprocessable()->assertOnlyJsonValidationErrors(['credential']);
    $this->assertGuest();
})->with(['別のオリジン', '署名の改ざん', '署名の後の書き換え', '古いチャレンジ']);

it('TC-311-06: ログインを始めずに完了だけを送ると 422', function () {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    $options = ['challenge' => SoftwareAuthenticator::b64(random_bytes(32))];

    $this->postJson('/api/auth/passkey/login', [
        'credential' => $authenticator->login($options, passkeyOrigin(), $admin->getPasskeyUserHandle()),
    ])->assertUnprocessable()->assertOnlyJsonValidationErrors(['credential']);
    $this->assertGuest();
});

it('TC-311-07: ログインしていないと、登録・一覧・削除・ログアウトは 401', function (string $method, string $uri) {
    $passkeyOwner = User::factory()->create();
    $this->actingAs($passkeyOwner);
    registerPasskeyVia($this, new SoftwareAuthenticator)->assertCreated();
    logoutForTest();

    $this->json($method, str_replace('{id}', (string) Passkey::query()->sole()->id, $uri))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(Passkey::query()->count())->toBe(1);
})->with([
    '登録の開始' => ['POST', '/api/admin/passkeys/registration-options'],
    '登録の完了' => ['POST', '/api/admin/passkeys'],
    '一覧' => ['GET', '/api/admin/passkeys'],
    '削除' => ['DELETE', '/api/admin/passkeys/{id}'],
    'ログアウト' => ['POST', '/api/admin/logout'],
]);

it('TC-311-08: ログインして登録すると 201 で名前・登録日時を返し、行が残る(まだ使っていないので最後に使った日は null)', function () {
    $this->freezeSecond();
    $admin = User::factory()->create();
    $authenticator = new SoftwareAuthenticator;

    $response = registerPasskeyVia($this->actingAs($admin), $authenticator, '仕事の MacBook')->assertCreated();

    $passkey = Passkey::query()->sole();
    $response->assertExactJson(['data' => [
        'id' => $passkey->id,
        'name' => '仕事の MacBook',
        'created_at' => now()->toJSON(),
        'last_used_at' => null,
    ]]);
    expect($passkey->user_id)->toBe($admin->id)
        ->and($passkey->credential_id)->toBe($authenticator->credentialId());
});

it('TC-311-09: 登録の開始は、この RP・本人確認を求める options を返し、登録済みのパスキーを除外に載せる', function () {
    $admin = User::factory()->create();
    $authenticator = new SoftwareAuthenticator;
    $this->actingAs($admin);

    $this->postJson('/api/admin/passkeys/registration-options')
        ->assertOk()
        ->assertJsonPath('options.rp.id', parse_url(passkeyOrigin(), PHP_URL_HOST))
        ->assertJsonPath('options.authenticatorSelection.userVerification', 'required')
        ->assertJsonPath('options.excludeCredentials', []);
    registerPasskeyVia($this, $authenticator)->assertCreated();

    $this->postJson('/api/admin/passkeys/registration-options')
        ->assertOk()
        ->assertJsonPath('options.excludeCredentials.*.id', [$authenticator->credentialId()]);
});

it('TC-311-10: 登録済みのパスキーをもう一度登録すると 422 で、行は増えない', function () {
    $authenticator = new SoftwareAuthenticator;
    $this->actingAs(User::factory()->create());
    registerPasskeyVia($this, $authenticator)->assertCreated();

    registerPasskeyVia($this, $authenticator)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['credential']);

    expect(Passkey::query()->count())->toBe(1);
});

it('TC-311-11: 名前は 1〜255 文字(空・256 文字は 422)', function (string $name, int $status) {
    $this->actingAs(User::factory()->create());

    $response = registerPasskeyVia($this, new SoftwareAuthenticator, $name)->assertStatus($status);

    if ($status === 422) {
        $response->assertOnlyJsonValidationErrors(['name']);
    }
    expect(Passkey::query()->count())->toBe($status === 201 ? 1 : 0);
})->with([
    '空' => ['', 422],
    '1 文字' => ['a', 201],
    '255 文字' => [str_repeat('鍵', 255), 201],
    '256 文字' => [str_repeat('鍵', 256), 422],
]);

it('TC-311-12: 登録を始めずに完了だけを送る・別のオリジンで作った応答は 422', function (string $case) {
    $authenticator = new SoftwareAuthenticator;
    $this->actingAs(User::factory()->create());

    if ($case === '開始していない') {
        $options = ['challenge' => SoftwareAuthenticator::b64(random_bytes(32))];
        $credential = $authenticator->register($options, passkeyOrigin());
    } else {
        $options = $this->postJson('/api/admin/passkeys/registration-options')->json('options');
        $credential = $authenticator->register($options, 'https://evil.example');
    }

    $this->postJson('/api/admin/passkeys', ['name' => 'x', 'credential' => $credential])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['credential']);
    expect(Passkey::query()->count())->toBe(0);
})->with(['開始していない', '別のオリジン']);

it('TC-311-13: 一覧は自分のパスキーだけを登録した順で返し、ログインに使った日時が入る', function () {
    $this->freezeSecond();
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    $registeredAt = now()->toJSON();
    $this->actingAs(User::factory()->create());
    registerPasskeyVia($this, new SoftwareAuthenticator, 'ほかの人の鍵')->assertCreated();
    logoutForTest();

    $this->travelTo(now()->addHour());
    loginWithPasskey($this, $authenticator, $admin)->assertOk();
    $this->travelTo(now()->addHour());
    registerPasskeyVia($this, new SoftwareAuthenticator, '予備の鍵')->assertCreated();

    $first = Passkey::query()->where('name', '仕事の MacBook')->sole();
    $second = Passkey::query()->where('name', '予備の鍵')->sole();
    $this->getJson('/api/admin/passkeys')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $first->id, 'name' => '仕事の MacBook', 'created_at' => $registeredAt, 'last_used_at' => now()->subHour()->toJSON()],
            ['id' => $second->id, 'name' => '予備の鍵', 'created_at' => now()->toJSON(), 'last_used_at' => null],
        ]]);
});

it('TC-311-14: 自分のパスキーを消すと 204 で行が消え、もう一度消すと 404', function () {
    $this->actingAs(User::factory()->create());
    registerPasskeyVia($this, new SoftwareAuthenticator, '消す鍵')->assertCreated();
    registerPasskeyVia($this, new SoftwareAuthenticator, '残す鍵')->assertCreated();
    $deleted = Passkey::query()->where('name', '消す鍵')->sole();
    $kept = Passkey::query()->where('name', '残す鍵')->sole();

    $this->deleteJson("/api/admin/passkeys/{$deleted->id}")->assertNoContent();
    $this->deleteJson("/api/admin/passkeys/{$deleted->id}")->assertNotFound();

    expect(Passkey::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('TC-311-15: ほかの人のパスキーは、消せずに 404(あるかどうかを教えない)', function () {
    $this->actingAs(User::factory()->create());
    registerPasskeyVia($this, new SoftwareAuthenticator)->assertCreated();
    $others = Passkey::query()->sole();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/admin/passkeys/{$others->id}")
        ->assertNotFound();

    expect(Passkey::query()->whereKey($others->id)->exists())->toBeTrue();
});

it('TC-311-16: 最後の 1 つのパスキーも消せる。消した後は、非常用のログイン URL で入れる', function () {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    loginWithPasskey($this, $authenticator, $admin)->assertOk();

    $this->deleteJson('/api/admin/passkeys/'.Passkey::query()->sole()->id)->assertNoContent();
    // 消したセッションは、ログインしたまま続く
    $this->getJson('/api/admin/passkeys')->assertOk()->assertExactJson(['data' => []]);

    logoutForTest();
    loginWithPasskey($this, $authenticator, $admin)->assertUnprocessable();
    $this->get(app(AdminLoginLinks::class)->issue($admin))->assertRedirect('/admin');
    $this->assertAuthenticatedAs($admin);
});

it('TC-311-17: ログアウトすると 204 で、そのセッションのクッキーではもう管理 API を呼べない', function () {
    $authenticator = new SoftwareAuthenticator;
    $admin = userWithPasskey($this, $authenticator);
    $login = loginWithPasskey($this, $authenticator, $admin)->assertOk();
    $cookie = config()->string('session.cookie');
    $session = $login->getCookie($cookie)?->getValue() ?? throw new RuntimeException('セッションのクッキーが無い');
    $this->app['auth']->forgetGuards();

    $this->withCookie($cookie, $session)->postJson('/api/admin/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();

    $this->assertGuest();
    $this->withCookie($cookie, $session)->getJson('/api/admin/me')->assertUnauthorized();
});

it('TC-311-18: ログインの開始・完了も、CSRF のトークンの無い書き込みは 419', function (string $uri) {
    $this->app->bind(PreventRequestForgery::class, fn (Application $app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->postJson($uri, [])->assertStatus(419);
})->with(['/api/auth/passkey/login-options', '/api/auth/passkey/login']);

it('TC-311-19: ログインは、同じ IP から 1 分に 10 回まで(11 回目は 429)', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/auth/passkey/login-options')->assertOk();
    }

    $this->postJson('/api/auth/passkey/login-options')->assertTooManyRequests();
    $this->postJson('/api/auth/passkey/login', [])->assertTooManyRequests();
});

it('TC-311-20: laravel/passkeys が置くルート(/passkeys/login・/user/passkeys など)は使わない', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

    expect($names->filter(fn (string $name) => str_starts_with($name, 'passkey.'))->all())->toBe([]);
    $this->postJson('/passkeys/login')->assertStatus(405);
});
