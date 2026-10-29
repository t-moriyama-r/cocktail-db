<?php

use App\Models\AdminLoginLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

// 非常用のログイン URL(php artisan admin:login-link。#115)。
// コマンドが出した URL を、そのままブラウザで開いたのと同じように GET して確かめる。
// 署名を作るときと確かめるときで URL の組み立て(ホスト・ルート名)が食い違うと入れなくなるので、URL は手で作らない。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->withoutVite();
    // 有効期限の境界を秒で確かめるため、発行の時刻を秒の頭にそろえて止める
    $this->freezeSecond();
});

/**
 * admin:login-link を流し、出力された URL を返す。
 *
 * @param  array<string, string>  $arguments
 */
function issueLoginLink(array $arguments = []): string
{
    expect(Artisan::call('admin:login-link', $arguments))->toBe(0);

    return trim(Artisan::output());
}

/**
 * admin:login-link を、出力と終了コードを確かめられる形で呼ぶ。
 *
 * @param  array<string, string>  $arguments
 */
function loginLinkCommand(TestCase $test, array $arguments = []): PendingCommand
{
    $command = $test->artisan('admin:login-link', $arguments);

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * ログイン URL のクエリの 1 つを差し替える。
 */
function withLoginLinkQuery(string $url, string $key, string $value): string
{
    $parts = parse_url($url);
    parse_str(is_array($parts) && isset($parts['query']) ? $parts['query'] : '', $query);
    $query[$key] = $value;

    return strtok($url, '?').'?'.http_build_query($query);
}

it('TC-115-01: 発行した URL を開くと、そのユーザーでログインして /admin に移る', function () {
    $admin = User::factory()->create();

    $this->get(issueLoginLink())->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin);
});

it('TC-115-02: 発行から 10 分ちょうどの URL では入れる', function () {
    $admin = User::factory()->create();
    $url = issueLoginLink();

    $this->travel(600)->seconds();

    $this->get($url)->assertRedirect('/admin');
    $this->assertAuthenticatedAs($admin);
});

it('TC-115-03: 発行から 10 分 1 秒たった URL では入れない', function () {
    User::factory()->create();
    $url = issueLoginLink();

    $this->travel(601)->seconds();

    $this->get($url)->assertForbidden();
    $this->assertGuest();
});

it('TC-115-04: 一度使った URL は、2 回目には入れない', function () {
    User::factory()->create();
    $url = issueLoginLink();
    $this->get($url)->assertRedirect('/admin');

    auth()->logout();
    $this->get($url)->assertForbidden();

    $this->assertGuest();
});

it('TC-115-05: URL のユーザーを別のユーザーに書き換えると入れない', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create();
    $url = issueLoginLink(['email' => $admin->email]);

    $tampered = str_replace("/admin/login-link/{$admin->id}?", "/admin/login-link/{$other->id}?", $url);
    expect($tampered)->not->toBe($url);

    $this->get($tampered)->assertForbidden();
    $this->assertGuest();
});

it('TC-115-06: 別のユーザーに発行した記録を指す URL では、そのユーザーとしても入れない', function () {
    // 署名は正しいが、記録の持ち主と URL のユーザーが食い違う(APP_KEY を知っている人が組み立てた URL など)
    $admin = User::factory()->create();
    $other = User::factory()->create();
    $url = issueLoginLink(['email' => $admin->email]);
    AdminLoginLink::query()->update(['user_id' => $other->id]);

    $this->get($url)->assertForbidden();
    $this->assertGuest();
});

it('TC-115-07: 署名を書き換えた URL では入れない', function () {
    User::factory()->create();
    $url = issueLoginLink();

    $this->get(withLoginLinkQuery($url, 'signature', str_repeat('0', 64)))->assertForbidden();
    $this->assertGuest();
});

it('TC-115-08: 署名を残したままトークン・期限を書き換えた URL では入れない', function (string $key, string $value) {
    User::factory()->create();
    $url = issueLoginLink();

    $this->get(withLoginLinkQuery($url, $key, $value))->assertForbidden();
    $this->assertGuest();
})->with([
    'トークン' => ['token', str_repeat('a', 64)],
    '期限を延ばす' => ['expires', (string) now()->addDay()->getTimestamp()],
]);

it('TC-115-09: 署名の無い URL では入れない', function () {
    $admin = User::factory()->create();

    $this->get("/admin/login-link/{$admin->id}?token=".str_repeat('a', 64))->assertForbidden();
    $this->assertGuest();
});

it('TC-115-10: 発行した後でユーザーを消すと、その URL では入れない', function () {
    $admin = User::factory()->create();
    $url = issueLoginLink();
    $admin->delete();

    $this->get($url)->assertForbidden();
    $this->assertGuest();
});

it('TC-115-11: ユーザーが 1 人もいなければ、URL を出さずに 1 で終わる', function () {
    loginLinkCommand($this)
        ->expectsOutputToContain('ユーザーがいない')
        ->assertExitCode(1);

    expect(AdminLoginLink::query()->count())->toBe(0);
});

it('TC-115-12: ユーザーが 2 人以上で email を指定しないと、URL を出さずに 1 で終わる', function () {
    User::factory()->count(2)->create();

    loginLinkCommand($this)
        ->expectsOutputToContain('email')
        ->assertExitCode(1);

    expect(AdminLoginLink::query()->count())->toBe(0);
});

it('TC-115-13: 指定した email のユーザーがいなければ、URL を出さずに 1 で終わる', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    loginLinkCommand($this, ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('nobody@example.com')
        ->assertExitCode(1);

    expect(AdminLoginLink::query()->count())->toBe(0);
});

it('TC-115-14: 2 人以上いても email を指定すれば、そのユーザーの URL を出す', function () {
    User::factory()->create();
    $target = User::factory()->create();

    $this->get(issueLoginLink(['email' => $target->email]))->assertRedirect('/admin');

    $this->assertAuthenticatedAs($target);
});

it('TC-115-15: DB にはトークンそのものを残さない', function () {
    User::factory()->create();
    $url = issueLoginLink();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? null;
    expect($token)->toBeString();

    $link = AdminLoginLink::query()->sole();

    expect($link->token_hash)->not->toBe($token)
        ->and($link->token_hash)->toBe(hash('sha256', is_string($token) ? $token : ''));
});

it('TC-115-16: 同じユーザーに続けて発行した URL は、それぞれ 1 回ずつ使える', function () {
    $admin = User::factory()->create();
    $first = issueLoginLink();
    $second = issueLoginLink();
    expect($second)->not->toBe($first);

    $this->get($first)->assertRedirect('/admin');
    auth()->logout();
    $this->get($second)->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin);
});

it('TC-115-17: ログインするとセッション ID を振り直す', function () {
    User::factory()->create();
    $this->startSession();
    $before = session()->getId();

    $this->get(issueLoginLink())->assertRedirect('/admin');

    expect(session()->getId())->not->toBe($before);
});
