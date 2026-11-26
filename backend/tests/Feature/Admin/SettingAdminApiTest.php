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
        'rakuten.application_id' => null,
        'rakuten.access_key' => null,
        'rakuten.affiliate_id' => null,
        'ga4.measurement_id' => null,
        'affiliates.interval_minutes' => null,
        'affiliates.refresh_limit' => null,
        'affiliates.new_limit' => null,
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

// GA4 の測定 ID(#59)を足して 7 項目、アフィリエイトの収集の間隔・件数(#826)を足して 10 項目になった
it('TC-132-01: 一覧は、定義の 10 項目を定義の順で返し、どこにも値が無ければ未設定として返す', function () {
    $response = $this->getJson('/api/admin/settings')->assertOk();
    $json = $response->json();

    expect($response->json('data.*.key'))->toBe([
        'amazon.credential_id', 'amazon.credential_secret', 'amazon.partner_tag',
        'rakuten.application_id', 'rakuten.access_key', 'rakuten.affiliate_id',
        'ga4.measurement_id',
        'affiliates.interval_minutes', 'affiliates.refresh_limit', 'affiliates.new_limit',
    ])
        ->and($response->json('data.*.secret'))->toBe([true, true, false, true, true, false, false, false, false, false])
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

it('TC-72-19: 楽天のアプリ ID・アクセスキーは秘密の項目で、画面で保存した値も .env の値も、一覧に平文で出ない', function () {
    app(Settings::class)->set('rakuten.access_key', 'SAVED-rakuten-access-key-WXYZ', $this->admin);
    config()->set('settings', array_merge(config()->array('settings'), ['rakuten.application_id' => 'ENV-rakuten-application-1234']));

    $response = $this->getJson('/api/admin/settings')->assertOk();

    expect($response->getContent())->not->toContain('SAVED-rakuten-access-key')
        ->and($response->getContent())->not->toContain('ENV-rakuten-application')
        ->and(settingItem($response->json(), 'rakuten.access_key'))->toMatchArray([
            'secret' => true, 'is_set' => true, 'value' => null, 'last_four' => 'WXYZ', 'source' => 'saved',
        ])
        ->and(settingItem($response->json(), 'rakuten.application_id'))->toMatchArray([
            'secret' => true, 'is_set' => true, 'value' => null, 'last_four' => '1234', 'source' => 'env',
        ]);
});

/**
 * 環境変数(.env の行)を一時的に書き換え、config/settings.php を読み直してから $callback を動かす(#689)。
 * .env に値の無い行があるときと同じく、env() が空の文字を返すようにするため。終わったら元に戻す。
 *
 * @param  array<string, string>  $values
 */
function withEnv(array $values, Closure $callback): void
{
    $saved = [];
    foreach ($values as $name => $value) {
        $saved[$name] = [
            'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            'hasServer' => array_key_exists($name, $_SERVER),
            'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            'hasEnv' => array_key_exists($name, $_ENV),
            'getenv' => getenv($name),
        ];
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    try {
        config()->set('settings', require base_path('config/settings.php'));
        $callback();
    } finally {
        foreach ($saved as $name => $old) {
            if ($old['hasServer']) {
                $_SERVER[$name] = $old['server'];
            } else {
                unset($_SERVER[$name]);
            }
            if ($old['hasEnv']) {
                $_ENV[$name] = $old['env'];
            } else {
                unset($_ENV[$name]);
            }
            putenv($old['getenv'] === false ? $name : "{$name}={$old['getenv']}");
        }
    }
}

// .env に値の無い行(`AMAZON_CREDENTIAL_ID=`)があるとき(#689)。旧には設定の画面も API も無い
it('TC-689-01: .env の値が空の行なら、一覧はどの項目も未設定(is_set: false・source: null)として返す', function () {
    withEnv([
        'AMAZON_CREDENTIAL_ID' => '',
        'AMAZON_CREDENTIAL_SECRET' => '',
        'AMAZON_PARTNER_TAG' => '',
        'RAKUTEN_APPLICATION_ID' => '',
        'RAKUTEN_ACCESS_KEY' => '',
        'RAKUTEN_AFFILIATE_ID' => '',
        'GA4_MEASUREMENT_ID' => '',
        'AFFILIATES_COLLECT_INTERVAL_MINUTES' => '',
        'AFFILIATES_REFRESH_LIMIT' => '',
        'AFFILIATES_NEW_LIMIT' => '',
    ], function () {
        // 前提: env() は値の無い行に空の文字を返す
        expect(array_values(array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', config()->array('settings')))))->toBe(['']);

        $response = $this->getJson('/api/admin/settings')->assertOk();

        expect($response->json('data.*.key'))->toHaveCount(10)
            ->and($response->json('data.*.is_set'))->toBe(array_fill(0, 10, false))
            ->and($response->json('data.*.value'))->toBe(array_fill(0, 10, null))
            ->and($response->json('data.*.last_four'))->toBe(array_fill(0, 10, null))
            ->and($response->json('data.*.source'))->toBe(array_fill(0, 10, null));
    });
});

it('TC-689-02: .env の値が空の行でも、画面で保存した値があれば、その値を saved として返す', function () {
    withEnv(['AMAZON_PARTNER_TAG' => '', 'AMAZON_CREDENTIAL_SECRET' => ''], function () {
        app(Settings::class)->set('amazon.partner_tag', 'saved-tag-22', $this->admin);
        app(Settings::class)->set('amazon.credential_secret', 'SECRET-plain-value-WXYZ', $this->admin);

        $json = $this->getJson('/api/admin/settings')->assertOk()->json();

        expect(settingItem($json, 'amazon.partner_tag'))->toMatchArray([
            'is_set' => true, 'value' => 'saved-tag-22', 'source' => 'saved',
        ])
            ->and(settingItem($json, 'amazon.credential_secret'))->toMatchArray([
                'is_set' => true, 'value' => null, 'last_four' => 'WXYZ', 'source' => 'saved',
            ]);
    });
});

it('TC-689-03: .env の値が「0」のような空でない文字なら、これまでどおり値あり(source: env)として返す', function () {
    withEnv(['RAKUTEN_AFFILIATE_ID' => '0'], function () {
        expect(settingItem($this->getJson('/api/admin/settings')->assertOk()->json(), 'rakuten.affiliate_id'))
            ->toMatchArray(['is_set' => true, 'value' => '0', 'source' => 'env']);
    });
});

// アフィリエイトの収集の間隔・1 回の件数(#826。docs/decisions/settings.md)。旧には設定の画面が無い(旧に無い)
it('TC-826-01: 収集の間隔・取り直す件数・新しく探す件数は、秘密でない項目として一覧に出て、.env の値を出どころ付きで返す', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['affiliates.interval_minutes' => '20']));

    $json = $this->getJson('/api/admin/settings')->assertOk()->json();

    expect(settingItem($json, 'affiliates.interval_minutes'))->toMatchArray([
        'label' => 'アフィリエイトの収集の間隔(分)', 'secret' => false, 'is_set' => true, 'value' => '20', 'last_four' => null, 'source' => 'env',
    ])
        ->and(settingItem($json, 'affiliates.refresh_limit'))->toMatchArray([
            'label' => 'アフィリエイトの 1 回に取り直す件数', 'secret' => false, 'is_set' => false, 'value' => null, 'source' => null,
        ])
        ->and(settingItem($json, 'affiliates.new_limit'))->toMatchArray([
            'label' => 'アフィリエイトの 1 回に新しく探す件数', 'secret' => false, 'is_set' => false, 'value' => null, 'source' => null,
        ]);
});

it('TC-826-02: 範囲の中の値は保存でき、一覧に画面で保存した値として出て、最後に変えた人が残る', function (string $key, string $value) {
    $this->putJson('/api/admin/settings', ['key' => $key, 'value' => $value])
        ->assertOk()
        ->assertJsonPath('data.value', $value)
        ->assertJsonPath('data.source', 'saved')
        ->assertJsonPath('data.updated_by.id', $this->admin->id);

    expect(app(Settings::class)->get($key))->toBe($value);
})->with([
    '間隔: 1' => ['affiliates.interval_minutes', '1'],
    '間隔: 59' => ['affiliates.interval_minutes', '59'],
    '取り直す件数: 1' => ['affiliates.refresh_limit', '1'],
    '取り直す件数: 100' => ['affiliates.refresh_limit', '100'],
    '新しく探す件数: 1' => ['affiliates.new_limit', '1'],
    '新しく探す件数: 100' => ['affiliates.new_limit', '100'],
]);

it('TC-826-03: 範囲の外・整数でない値は 422 で、メッセージは 1 つだけ出て、行は増えない', function (string $key, string $value) {
    $response = $this->putJson('/api/admin/settings', ['key' => $key, 'value' => $value])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['value']);

    expect($response->json('errors.value'))->toHaveCount(1)
        ->and(Setting::query()->count())->toBe(0);
})->with([
    '間隔: 0' => ['affiliates.interval_minutes', '0'],
    '間隔: 60' => ['affiliates.interval_minutes', '60'],
    '間隔: -5' => ['affiliates.interval_minutes', '-5'],
    '間隔: 小数' => ['affiliates.interval_minutes', '1.5'],
    '間隔: 文字' => ['affiliates.interval_minutes', 'abc'],
    '間隔: 先頭に +' => ['affiliates.interval_minutes', '+5'],
    '間隔: 先頭に 0' => ['affiliates.interval_minutes', '05'],
    '取り直す件数: 0' => ['affiliates.refresh_limit', '0'],
    '取り直す件数: 101' => ['affiliates.refresh_limit', '101'],
    '新しく探す件数: 0' => ['affiliates.new_limit', '0'],
    '新しく探す件数: 101' => ['affiliates.new_limit', '101'],
    '新しく探す件数: 全角' => ['affiliates.new_limit', '5'],
]);
