<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// 画面から変える設定の保存と読み出し(#131)。
// 暗号化されているかは、モデルを通すと復号されてしまうので、表の列をそのまま読んで確かめる。

uses(RefreshDatabase::class)->group('db');

function settings(): Settings
{
    return app(Settings::class);
}

it('TC-131-01: 保存した値は暗号化されて DB に入り、APP_KEY で復号すると元の値になる', function () {
    settings()->set('amazon.secret', 'plain-secret-value');

    $raw = DB::table('settings')->where('key', 'amazon.secret')->value('value');

    expect($raw)->toBeString();
    assert(is_string($raw));
    expect($raw)->not->toContain('plain-secret-value')
        ->and(Crypt::decryptString($raw))->toBe('plain-secret-value');
});

it('TC-131-02: DB に値があれば、.env の値より DB の値を返す', function () {
    config()->set('settings', ['ga4.measurement_id' => 'G-FROM-ENV']);
    settings()->set('ga4.measurement_id', 'G-FROM-DB');

    expect(settings()->get('ga4.measurement_id', 'G-DEFAULT'))->toBe('G-FROM-DB');
});

it('TC-131-03: DB に行が無ければ、.env の値を返す', function () {
    config()->set('settings', ['ga4.measurement_id' => 'G-FROM-ENV']);

    expect(settings()->get('ga4.measurement_id', 'G-DEFAULT'))->toBe('G-FROM-ENV');
});

it('TC-131-04: DB にも .env にも無ければ、既定値を返す(既定値を渡さなければ null)', function () {
    config()->set('settings', []);

    expect(settings()->get('ga4.measurement_id', 'G-DEFAULT'))->toBe('G-DEFAULT')
        ->and(settings()->get('ga4.measurement_id'))->toBeNull();
});

it('TC-131-05: 保存した値が null なら、.env の値に戻る', function () {
    config()->set('settings', ['ga4.measurement_id' => 'G-FROM-ENV']);
    settings()->set('ga4.measurement_id', 'G-FROM-DB');
    settings()->set('ga4.measurement_id', null);

    expect(settings()->get('ga4.measurement_id'))->toBe('G-FROM-ENV')
        ->and(Setting::query()->where('key', 'ga4.measurement_id')->count())->toBe(1);
});

it('TC-131-06: 空文字を保存したら、.env に戻さず空文字を返す', function () {
    config()->set('settings', ['rakuten.affiliate_id' => 'FROM-ENV']);
    settings()->set('rakuten.affiliate_id', '');

    expect(settings()->get('rakuten.affiliate_id'))->toBe('');
});

it('TC-131-07: 保存した値を復号できない(別の APP_KEY で暗号化した)ときは、.env の値を返してログに残す', function () {
    config()->set('settings', ['amazon.secret' => 'FROM-ENV']);
    $otherKey = new Encrypter(random_bytes(32), 'AES-256-CBC');
    DB::table('settings')->insert([
        'key' => 'amazon.secret',
        'value' => $otherKey->encryptString('encrypted-with-other-key'),
    ]);
    Log::shouldReceive('warning')->once()->with(Mockery::any(), ['key' => 'amazon.secret']);

    expect(settings()->get('amazon.secret'))->toBe('FROM-ENV');
});

it('TC-131-08: 保存し直すと値を上書きし、行は 1 件のまま、変えた人を記録する', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    settings()->set('rakuten.app_id', 'OLD', $first);
    settings()->set('rakuten.app_id', 'NEW', $second);

    $row = Setting::query()->where('key', 'rakuten.app_id')->sole();
    expect($row->value)->toBe('NEW')
        ->and($row->updated_by)->toBe($second->id)
        ->and(settings()->get('rakuten.app_id'))->toBe('NEW');
});

it('TC-131-09: 変えた人を渡さずに保存すると、変えた人は null になる', function () {
    $user = User::factory()->create();
    settings()->set('rakuten.app_id', 'OLD', $user);
    settings()->set('rakuten.app_id', 'NEW');

    expect(Setting::query()->where('key', 'rakuten.app_id')->value('updated_by'))->toBeNull();
});

it('TC-131-10: キーに「.」を含めても、.env の値を入れ子の配列として辿らない', function () {
    config()->set('settings', [
        'amazon.partner_tag' => 'TAG-FROM-ENV',
        'amazon' => ['partner_tag' => 'NESTED'],
    ]);

    expect(settings()->get('amazon.partner_tag'))->toBe('TAG-FROM-ENV');
});

it('TC-131-11: キーごとに別の値を持ち、ほかのキーの値を返さない', function () {
    config()->set('settings', []);
    settings()->set('a.key', 'A');

    expect(settings()->get('b.key', 'B-DEFAULT'))->toBe('B-DEFAULT');
});
