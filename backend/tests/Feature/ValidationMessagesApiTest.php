<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 管理 API・公開の API の 422 のメッセージが日本語になる(#651。決めたことは docs/decisions/validation-messages.md)。
// 旧での結果は未確認(旧システムを動かせる環境で確かめていない)。
// 管理 API の欄の名前は、#692 で日本語(画面の欄の名前)にした(tests/Feature/Admin/ValidationAttributeNamesApiTest.php)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-651-03: 設定の画面で規則に合わない値を保存すると、errors.value と message が日本語になる', function () {
    $this->putJson('/api/admin/settings', ['key' => 'amazon.partner_tag', 'value' => 'has space'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Amazon のパートナータグの形が正しくありません。',
            'errors' => ['value' => ['Amazon のパートナータグの形が正しくありません。']],
        ]);
});

it('TC-651-04: 誤りが 2 つ以上あると、message は 1 つめのメッセージに「(ほか N 件)」を付ける', function () {
    $this->putJson('/api/admin/settings', [])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => '項目を入力してください。 (ほか 1 件)',
            'errors' => [
                'key' => ['項目を入力してください。'],
                'value' => ['値の欄を送ってください。'],
            ],
        ]);
});

// 欄の名前の「_」は、Laravel が空白に置き換えて出す
it('TC-651-05: 設定のほかの管理 API(銘柄の一覧)の 422 も日本語になる', function () {
    $this->getJson('/api/admin/brands?per_page=51')
        ->assertUnprocessable()
        ->assertJsonPath('errors.per_page', ['per pageは50以下にしてください。']);
});

it('TC-651-06: 公開の API(条件検索)の 422 も日本語になり、真偽値を ID に送ったときの独自のメッセージも日本語になる', function () {
    $this->postJson('/api/cocktails/search', ['mode' => 'and', 'page' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.page', ['pageは整数にしてください。']);
});
