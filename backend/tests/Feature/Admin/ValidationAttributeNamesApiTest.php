<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 管理 API の 422 のメッセージで、欄の名前(:attribute)を日本語(画面の欄の名前)にする(#692。
// 決めたことは docs/decisions/validation-messages.md)。旧での結果は未確認(旧システムを動かせる環境で確かめていない)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-692-01: 設定で規則に合わない値を保存すると、value の名前がその項目の見出しになる', function () {
    $this->putJson('/api/admin/settings', ['key' => 'rakuten.application_id', 'value' => "has\nnewline"])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => '楽天のアプリ IDの形が正しくありません。',
            'errors' => ['value' => ['楽天のアプリ IDの形が正しくありません。']],
        ]);
});

it('TC-692-02: 設定で定義に無いキーを送ると、key は「項目」、value は「値」で出る', function () {
    $this->putJson('/api/admin/settings', ['key' => 'no.such_key', 'value' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => '選んだ項目は正しくありません。 (ほか 1 件)',
            'errors' => [
                'key' => ['選んだ項目は正しくありません。'],
                'value' => ['値は255 文字以下にしてください。'],
            ],
        ]);
});

it('TC-692-03: 銘柄で必須の欄を空にすると、欄の名前が画面の名前で出る', function () {
    $this->postJson('/api/admin/brands', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.category_id', ['所属を入力してください。'])
        ->assertJsonPath('errors.name', ['名前を入力してください。'])
        ->assertJsonPath('errors.name_eng', ['英名を入力してください。'])
        ->assertJsonPath('errors.strength', ['度数を入力してください。'])
        ->assertJsonPath('errors.description', ['説明を入力してください。'])
        ->assertJsonPath('errors.is_major', ['メジャーを入力してください。'])
        ->assertJsonPath('errors.is_like', ['お気に入りを入力してください。']);
});

it('TC-692-04: 銘柄の度数・開始位置・リンク URL の誤りも、欄の名前が日本語になる', function () {
    $this->postJson('/api/admin/brands', [
        'strength' => 101,
        'youtube_start_minute' => 60,
        'image_url' => 'javascript:alert(1)',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.strength', ['度数は0〜100にしてください。'])
        ->assertJsonPath('errors.youtube_start_minute', ['開始位置(分)は59以下にしてください。'])
        ->assertJsonPath('errors.image_url', ['リンク URLは正しい URL にしてください。']);
});

it('TC-692-05: カクテルの材料・別名の誤りは、何行目・何番目かを付けて出る', function () {
    $this->postJson('/api/admin/cocktails', [
        'ingredients' => [
            ['category_id' => 999999, 'amount' => '30ml'],
            ['category_id' => 999999],
        ],
        'aliases' => [str_repeat('あ', 256)],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.base_category_id', ['ベースを入力してください。'])
        ->assertJsonPath('errors.method_id', ['技法を入力してください。'])
        ->assertJsonPath('errors.is_draft', ['下書きを入力してください。'])
        ->assertJsonPath('errors', fn (array $errors) => ($errors['ingredients.0.category_id'] ?? null) === ['選んだ材料 1 行目のノードは正しくありません。']
            && ($errors['ingredients.1.amount'] ?? null) === ['材料 2 行目の量を入力してください。']
            && ($errors['aliases.0'] ?? null) === ['別名 1 つ目は255 文字以下にしてください。']);
});

it('TC-692-06: ノードの、その場で作る銘柄の欄は「有名な銘柄の」を付けて出る', function () {
    $this->postJson('/api/admin/categories', [
        'parent_id' => null,
        'brand_name' => 'ロンリコ',
        'brand_youtube_start_second' => 60,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', ['名前を入力してください。'])
        ->assertJsonPath('errors.is_powder', ['粉末を入力してください。'])
        ->assertJsonPath('errors.brand_name', ['有名な銘柄の名前は入れられません。'])
        ->assertJsonPath('errors.brand_youtube_start_second', ['有名な銘柄の開始位置(秒)は59以下にしてください。']);
});

it('TC-692-07: 用語辞典で必須の欄を空にすると、分類も日本語で出る', function () {
    $this->postJson('/api/admin/dictionaries', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.dictionary_category_id', ['分類を入力してください。'])
        ->assertJsonPath('errors.name', ['名前を入力してください。']);
});

it('TC-692-08: 言い換えでは、name は「変換前」、converted は「変換後」で出る', function () {
    $this->postJson('/api/admin/paraphrases', ['command' => 'cold'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name', ['変換前を入力してください。'])
        ->assertJsonPath('errors.converted', ['変換後を入力してください。'])
        ->assertJsonPath('errors.command', ['選んだcommandは正しくありません。']);
});

it('TC-692-09: スキップ語では、name は「スキップ語」で出る', function () {
    $this->postJson('/api/admin/skip-words', ['name' => str_repeat('a', 257)])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'スキップ語は256 文字以下にしてください。',
            'errors' => ['name' => ['スキップ語は256 文字以下にしてください。']],
        ]);
});
