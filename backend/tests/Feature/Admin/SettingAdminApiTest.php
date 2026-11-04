<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// 管理 API の設定(/api/admin/settings。#132。決めたことは docs/decisions/settings.md)。
// 旧には設定の画面も API も無い(鍵はサーバーのファイルに書いていた)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => '管理者']);
    $this->actingAs($this->admin);
    config()->set('settings', [
        'amazon.credential_id' => null,
        'amazon.credential_secret' => null,
        'amazon.partner_tag' => null,
        'rakuten.affiliate_id' => null,
    ]);
});

/**
 * 一覧(`{data: [...]}` の JSON)の中から、キーの 1 項目を返す。
 *
 * @return array<mixed>
 */
function settingItem(mixed $json, string $key): array
{
    $data = is_array($json) ? ($json['data'] ?? null) : null;
    foreach (is_array($data) ? $data : [] as $item) {
        if (is_array($item) && ($item['key'] ?? null) === $key) {
            return $item;
        }
    }
    throw new RuntimeException("一覧に {$key} が無い");
}

it('TC-132-01: 一覧は、定義の 4 項目を定義の順で返し、どこにも値が無ければ未設定として返す', function () {
    $response = $this->getJson('/api/admin/settings')->assertOk();
    $json = $response->json();

    expect($response->json('data.*.key'))->toBe([
        'amazon.credential_id', 'amazon.credential_secret', 'amazon.partner_tag', 'rakuten.affiliate_id',
    ])
        ->and($response->json('data.*.secret'))->toBe([true, true, false, false])
        ->and(settingItem($json, 'amazon.partner_tag'))->toBe([
            'key' => 'amazon.partner_tag',
            'label' => 'Amazon のパートナータグ',
            'description' => 'Amazon へのリンクと API の呼び出しに付けるトラッキング ID。空にすると、タグを付けずにリンクする',
            'secret' => false,
            'is_set' => false,
            'value' => null,
            'last_four' => null,
            'source' => null,
            'updated_at' => null,
            'updated_by' => null,
        ]);
});

it('TC-132-02: 画面で保存した秘密の値は、一覧に平文で出ず、末尾 4 文字だけが出る', function () {
    app(Settings::class)->set('amazon.credential_secret', 'SECRET-plain-value-WXYZ', $this->admin);

    $response = $this->getJson('/api/admin/settings')->assertOk();

    expect($response->getContent())->not->toContain('SECRET-plain-value')
        ->and(settingItem($response->json(), 'amazon.credential_secret'))->toMatchArray([
            'secret' => true,
            'is_set' => true,
            'value' => null,
            'last_four' => 'WXYZ',
            'source' => 'saved',
        ]);
});

it('TC-132-03: .env から来た秘密の値も、一覧に平文で出ず、末尾 4 文字だけが出る', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['amazon.credential_id' => 'ENV-credential-id-1234']));

    $response = $this->getJson('/api/admin/settings')->assertOk();

    expect($response->getContent())->not->toContain('ENV-credential-id')
        ->and(settingItem($response->json(), 'amazon.credential_id'))->toMatchArray([
            'is_set' => true,
            'value' => null,
            'last_four' => '1234',
            'source' => 'env',
            'updated_at' => null,
            'updated_by' => null,
        ]);
});

it('TC-132-04: 秘密の値の末尾 4 文字は、8 文字以上の値だけに出す', function (string $secret, ?string $lastFour) {
    app(Settings::class)->set('amazon.credential_secret', $secret);

    $item = settingItem($this->getJson('/api/admin/settings')->assertOk()->json(), 'amazon.credential_secret');

    expect($item['is_set'])->toBeTrue()
        ->and($item['value'])->toBeNull()
        ->and($item['last_four'])->toBe($lastFour);
})->with([
    '7 文字' => ['abcdefg', null],
    '8 文字' => ['abcdefgh', 'efgh'],
]);

it('TC-132-05: 秘密でない項目は、値をそのまま返し、出どころを返す', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['rakuten.affiliate_id' => 'env.affiliate.id']));
    app(Settings::class)->set('amazon.partner_tag', 'saved-tag-22');

    $json = $this->getJson('/api/admin/settings')->assertOk()->json();

    expect(settingItem($json, 'amazon.partner_tag'))->toMatchArray(['value' => 'saved-tag-22', 'last_four' => null, 'source' => 'saved'])
        ->and(settingItem($json, 'rakuten.affiliate_id'))->toMatchArray(['value' => 'env.affiliate.id', 'last_four' => null, 'source' => 'env']);
});

it('TC-132-06: 書き換えると、値が暗号化して保存され、変えた人と日時が残って応答に出る', function () {
    $this->travelTo('2026-11-01 12:34:56');

    $this->putJson('/api/admin/settings', ['key' => 'amazon.partner_tag', 'value' => 'new-tag-22'])
        ->assertOk()
        ->assertJsonPath('data.key', 'amazon.partner_tag')
        ->assertJsonPath('data.value', 'new-tag-22')
        ->assertJsonPath('data.source', 'saved')
        ->assertJsonPath('data.updated_at', '2026-11-01T12:34:56.000000Z')
        ->assertJsonPath('data.updated_by', ['id' => $this->admin->id, 'name' => '管理者']);

    $raw = DB::table('settings')->where('key', 'amazon.partner_tag')->first();
    assert($raw !== null && is_string($raw->value));
    expect($raw->value)->not->toContain('new-tag-22')
        ->and(Crypt::decryptString($raw->value))->toBe('new-tag-22')
        ->and($raw->updated_by)->toBe($this->admin->id);

    expect(settingItem($this->getJson('/api/admin/settings')->json(), 'amazon.partner_tag'))->toMatchArray([
        'updated_at' => '2026-11-01T12:34:56.000000Z',
        'updated_by' => ['id' => $this->admin->id, 'name' => '管理者'],
    ]);
});

it('TC-132-07: 秘密の値を書き換えた応答にも、平文は出ず末尾 4 文字だけが出る', function () {
    $response = $this->putJson('/api/admin/settings', ['key' => 'amazon.credential_secret', 'value' => 'brand-new-secret-9876'])
        ->assertOk()
        ->assertJsonPath('data.value', null)
        ->assertJsonPath('data.last_four', '9876')
        ->assertJsonPath('data.is_set', true);

    expect($response->getContent())->not->toContain('brand-new-secret')
        ->and(app(Settings::class)->get('amazon.credential_secret'))->toBe('brand-new-secret-9876');
});

it('TC-132-08: null か空の文字で書き換えると .env の値に戻り、変えた人と日時は残る', function (?string $empty) {
    config()->set('settings', array_merge(config()->array('settings'), ['amazon.partner_tag' => 'env-tag-22']));
    app(Settings::class)->set('amazon.partner_tag', 'saved-tag-22');
    $this->travelTo('2026-11-02 09:00:00');

    $this->putJson('/api/admin/settings', ['key' => 'amazon.partner_tag', 'value' => $empty])
        ->assertOk()
        ->assertJsonPath('data.value', 'env-tag-22')
        ->assertJsonPath('data.source', 'env')
        ->assertJsonPath('data.updated_at', '2026-11-02T09:00:00.000000Z')
        ->assertJsonPath('data.updated_by.id', $this->admin->id);

    expect(Setting::query()->where('key', 'amazon.partner_tag')->count())->toBe(1)
        ->and(app(Settings::class)->get('amazon.partner_tag'))->toBe('env-tag-22');
})->with([
    'null' => [null],
    '空の文字' => [''],
]);

it('TC-132-09: 定義に無いキーは 422 で、行は増えない', function (mixed $key) {
    $this->putJson('/api/admin/settings', ['key' => $key, 'value' => 'x'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['key']);

    expect(Setting::query()->count())->toBe(0);
})->with([
    '定義に無いキー' => ['app.key'],
    '大文字にしたキー' => ['AMAZON.PARTNER_TAG'],
    '省いた' => [null],
    '配列' => [['amazon.partner_tag']],
]);

it('TC-132-10: value を省いた・文字でない値は 422 で、行は増えない', function (array $body) {
    $this->putJson('/api/admin/settings', ['key' => 'amazon.partner_tag', ...$body])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['value']);

    expect(Setting::query()->count())->toBe(0);
})->with([
    '省いた' => [[]],
    '数' => [['value' => 22]],
    '真偽値' => [['value' => true]],
    '配列' => [['value' => ['a']]],
]);

it('TC-132-11: 値の形と長さは項目ごとに確かめる', function (string $key, string $value, int $status) {
    $this->putJson('/api/admin/settings', ['key' => $key, 'value' => $value])->assertStatus($status);
})->with([
    'タグ: 英数字と記号' => ['amazon.partner_tag', 'my_tag.name-22', 200],
    'タグ: 100 文字' => ['amazon.partner_tag', str_repeat('a', 100), 200],
    'タグ: 101 文字' => ['amazon.partner_tag', str_repeat('a', 101), 422],
    'タグ: 空白を含む' => ['amazon.partner_tag', 'my tag', 422],
    'タグ: URL の記号を含む' => ['amazon.partner_tag', 'tag&x=1', 422],
    'タグ: 全角' => ['rakuten.affiliate_id', 'あいう', 422],
    'タグ: 改行を含む' => ['rakuten.affiliate_id', "abc\ndef", 422],
    '秘密: 記号を含む' => ['amazon.credential_secret', 'a+b/c=d!', 200],
    '秘密: 255 文字' => ['amazon.credential_secret', str_repeat('a', 255), 200],
    '秘密: 256 文字' => ['amazon.credential_secret', str_repeat('a', 256), 422],
    '秘密: 間に空白' => ['amazon.credential_id', 'abc def', 422],
    '秘密: 全角' => ['amazon.credential_id', 'あいうえおかきく', 422],
]);

it('TC-132-12: ログインしていなければ、設定の API はどれも 401 で、値は変わらない', function (string $method) {
    app(Settings::class)->set('amazon.partner_tag', 'saved-tag-22');
    auth()->logout();
    $this->app['auth']->forgetGuards();

    $this->json($method, '/api/admin/settings', ['key' => 'amazon.partner_tag', 'value' => 'changed'])->assertUnauthorized();

    expect(app(Settings::class)->get('amazon.partner_tag'))->toBe('saved-tag-22');
})->with(['一覧' => ['GET'], '書き換え' => ['PUT']]);

it('TC-132-13: 定義のキーは、config/settings.php(.env の値)のキーとそろっている', function () {
    $config = require base_path('config/settings.php');
    $configKeys = is_array($config) ? array_keys($config) : [];

    expect(app(SettingDefinitions::class)->keys())->toEqualCanonicalizing($configKeys);
});

it('TC-132-14: 変えた人のユーザーを消しても、一覧は返り、変えた人は null になる', function () {
    $other = User::factory()->create();
    app(Settings::class)->set('amazon.partner_tag', 'saved-tag-22', $other);
    $other->delete();

    expect(settingItem($this->getJson('/api/admin/settings')->assertOk()->json(), 'amazon.partner_tag'))
        ->toMatchArray(['value' => 'saved-tag-22', 'updated_by' => null]);
});
