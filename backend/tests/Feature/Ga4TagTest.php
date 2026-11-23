<?php

use App\Models\Cocktail;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// SPA の HTML に入れるアクセス解析(GA4)のタグ(#59)。決めたことと旧との差分は docs/decisions/analytics.md。
// 測定 ID は設定(#69。App\Support\Settings)から読む。テストの ID は本番の ID ではない仮の値。
// 設定を読めないときの HTML は Ga4TagUnreadableSettingsTest(DB を使わない)。

uses(RefreshDatabase::class)->group('db');

const GA4_TEST_ID = 'G-TEST1234AB';
const GA4_SCRIPT_URL = 'https://www.googletagmanager.com/gtag/js?id=';

beforeEach(function () {
    $this->withoutVite();
    config()->set('settings', array_merge(config()->array('settings'), ['ga4.measurement_id' => null]));
});

/**
 * HTML に入った GA4 のタグ(gtag.js の読み込みと config の呼び出し)を返す。無ければ null の組。
 *
 * @param  TestResponse<Response>  $response
 * @return array{src: ?string, config: ?string}
 */
function ga4Tag(TestResponse $response): array
{
    $html = (string) $response->getContent();
    preg_match('#<script async src="(https://www\.googletagmanager\.com/gtag/js\?id=[^"]*)"></script>#', $html, $src);
    preg_match("#gtag\\('config', (.*?)\\);#", $html, $config);

    return ['src' => $src[1] ?? null, 'config' => $config[1] ?? null];
}

it('TC-59-01: 測定 ID が設定にも .env にも無ければ、GA4 のタグを入れない', function (string $path) {
    $response = $this->get($path)->assertOk();

    expect(ga4Tag($response))->toBe(['src' => null, 'config' => null])
        ->and((string) $response->getContent())->not->toContain('googletagmanager.com')
        ->and((string) $response->getContent())->not->toContain('gtag(');
})->with(['トップ' => '/', '用語辞典' => '/dictionary', 'プライバシーポリシー' => '/privacy']);

it('TC-59-02: 画面で保存した測定 ID があれば、その ID で gtag.js を読み込み、最初の page_view は送らない設定にする', function (string $path) {
    app(Settings::class)->set('ga4.measurement_id', GA4_TEST_ID);

    expect(ga4Tag($this->get($path)->assertOk()))->toBe([
        'src' => GA4_SCRIPT_URL.GA4_TEST_ID,
        'config' => '"'.GA4_TEST_ID.'", { send_page_view: false }',
    ]);
})->with(['トップ' => '/', '用語辞典' => '/dictionary', 'プライバシーポリシー' => '/privacy', '知らない URL' => '/nope']);

it('TC-59-03: 画面で保存していなければ、.env の測定 ID で入れる。保存した値があればそちらを使う', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['ga4.measurement_id' => 'G-FROMENV1']));

    expect(ga4Tag($this->get('/')->assertOk())['src'])->toBe(GA4_SCRIPT_URL.'G-FROMENV1');

    app(Settings::class)->set('ga4.measurement_id', GA4_TEST_ID);

    expect(ga4Tag($this->get('/')->assertOk())['src'])->toBe(GA4_SCRIPT_URL.GA4_TEST_ID);
});

it('TC-59-04: 詳細ページの HTML と、無い ID の 404 の HTML にも入れる', function () {
    app(Settings::class)->set('ga4.measurement_id', GA4_TEST_ID);
    $cocktail = Cocktail::factory()->create();

    expect(ga4Tag($this->get('/detail/cocktail/'.$cocktail->id)->assertOk())['src'])->toBe(GA4_SCRIPT_URL.GA4_TEST_ID)
        ->and(ga4Tag($this->get('/detail/cocktail/999999')->assertNotFound())['src'])->toBe(GA4_SCRIPT_URL.GA4_TEST_ID);
});

it('TC-59-05: 管理画面(/admin 以下)の HTML には、測定 ID があっても入れない', function (string $path) {
    app(Settings::class)->set('ga4.measurement_id', GA4_TEST_ID);

    $response = $this->get($path)->assertOk();

    expect(ga4Tag($response))->toBe(['src' => null, 'config' => null])
        ->and((string) $response->getContent())->not->toContain('googletagmanager.com');
})->with(['管理画面' => '/admin', 'ログイン' => '/admin/login', '設定' => '/admin/settings']);

it('TC-59-06: 測定 ID の形が違う(.env に書いた値は保存の検査を通らない)ときは、タグを入れずにログに残す', function (string $value) {
    config()->set('settings', array_merge(config()->array('settings'), ['ga4.measurement_id' => $value]));
    Log::shouldReceive('warning')->once()->with(Mockery::any(), ['key' => 'ga4.measurement_id']);

    $response = $this->get('/')->assertOk();

    expect(ga4Tag($response))->toBe(['src' => null, 'config' => null])
        ->and((string) $response->getContent())->not->toContain('googletagmanager.com');
})->with([
    'UA の ID' => ['UA-12345-1'],
    '小文字' => ['g-test1234'],
    '「G-」だけ' => ['G-'],
    'HTML を閉じる記号' => ['G-AB"></script><script>alert(1)</script>'],
    '31 文字' => ['G-'.str_repeat('A', 29)],
]);

it('TC-59-07: 管理 API で測定 ID を保存でき、形の違う値は 422 で保存しない', function (string $value, int $status) {
    $this->actingAs(User::factory()->create());

    $this->putJson('/api/admin/settings', ['key' => 'ga4.measurement_id', 'value' => $value])->assertStatus($status);

    expect(app(Settings::class)->get('ga4.measurement_id'))->toBe($status === 200 ? $value : null);
})->with([
    'G- と英大文字・数字' => [GA4_TEST_ID, 200],
    '30 文字' => ['G-'.str_repeat('A', 28), 200],
    '31 文字' => ['G-'.str_repeat('A', 29), 422],
    'UA の ID' => ['UA-12345-1', 422],
    '小文字' => ['g-test1234', 422],
    '空白を含む' => ['G-TEST 1234', 422],
    'HTML を閉じる記号' => ['G-AB"><script>', 422],
]);
