<?php

use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Method;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 用語辞典の API(GET /api/dictionaries・GET /api/dictionaries/{id}。#46)。
// 期待値は、旧 DictionaryController(index・detail)と旧の画面(Dictionary/index.vue・detail.vue)の挙動と、
// docs/decisions/dictionary.md の旧との差分から置く。

uses(RefreshDatabase::class)->group('db');

/**
 * 分類を作る。ID を渡せばその ID で作る(グラスの分類は ID で見分けるため)。
 */
function dictionaryCategory(string $name, string $nameEng, ?int $id = null): DictionaryCategory
{
    return DictionaryCategory::factory()->create(array_filter(['id' => $id, 'name' => $name, 'name_eng' => $nameEng]));
}

/**
 * 用語を作る。
 *
 * @param  array<string, mixed>  $attributes
 */
function dictionaryTerm(DictionaryCategory $category, string $name, string $nameEng, array $attributes = []): Dictionary
{
    return Dictionary::factory()->create([
        'dictionary_category_id' => $category->id, 'name' => $name, 'name_eng' => $nameEng, 'name_search' => $name,
    ] + $attributes);
}

beforeEach(function () {
    $this->withoutVite();
});

it('TC-46-01: 一覧は、分類を ID の順に、分類ごとの用語の ID・名前・英名を返す', function () {
    // 旧の分類と同じ並び(製法・グラス・器具)を、ID の逆の順に作っても ID の順で返す
    $tool = dictionaryCategory('器具', 'Tool', 4);
    $glass = dictionaryCategory('グラス', 'Glass', DictionaryCategory::GLASS_ID);
    $method = dictionaryCategory('製法', 'Method', 1);
    $shake = dictionaryTerm($method, 'シェイク', 'Shake');
    $cocktailGlass = dictionaryTerm($glass, 'カクテル・グラス', 'Cocktail glass');
    $shaker = dictionaryTerm($tool, 'シェイカー', 'Shaker');

    $this->getJson('/api/dictionaries')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $method->id, 'name' => '製法', 'name_eng' => 'Method', 'dictionaries' => [
                ['id' => $shake->id, 'name' => 'シェイク', 'name_eng' => 'Shake'],
            ]],
            ['id' => $glass->id, 'name' => 'グラス', 'name_eng' => 'Glass', 'dictionaries' => [
                ['id' => $cocktailGlass->id, 'name' => 'カクテル・グラス', 'name_eng' => 'Cocktail glass'],
            ]],
            ['id' => $tool->id, 'name' => '器具', 'name_eng' => 'Tool', 'dictionaries' => [
                ['id' => $shaker->id, 'name' => 'シェイカー', 'name_eng' => 'Shaker'],
            ]],
        ]]);
});

it('TC-46-02: 一覧の用語は、カタカナをひらがなに・全角の英数字を半角にそろえた名前の順(同じ名前は ID の順)', function () {
    $category = dictionaryCategory('器具', 'Tool');
    // 作る順と名前の順をずらす。「ステア」と「すぷーん」はカタカナ・ひらがなが混ざっても五十音の順になる
    $spoon = dictionaryTerm($category, 'すぷーん', 'Spoon');
    $stir = dictionaryTerm($category, 'ステア', 'Stir');
    $zenkaku = dictionaryTerm($category, 'Ｂ', 'B');
    $hankaku = dictionaryTerm($category, 'A', 'A');
    $shakerFirst = dictionaryTerm($category, 'シェイカー', 'Shaker 1');
    $shakerSecond = dictionaryTerm($category, 'シェイカー', 'Shaker 2');

    $this->getJson('/api/dictionaries')
        ->assertOk()
        ->assertJsonPath('data.0.dictionaries.*.id', [
            $hankaku->id, $zenkaku->id, $shakerFirst->id, $shakerSecond->id, $stir->id, $spoon->id,
        ]);
});

it('TC-46-03: 用語の無い分類も、空の並びで返す', function () {
    $empty = dictionaryCategory('単位', 'Unit');

    $this->getJson('/api/dictionaries')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $empty->id, 'name' => '単位', 'name_eng' => 'Unit', 'dictionaries' => []],
        ]]);
});

it('TC-46-04: 詳細は、名前・英名・分類・説明文(記法のまま)・動画・画像を返す', function () {
    $category = dictionaryCategory('器具', 'Tool', 4);
    $term = dictionaryTerm($category, 'シェイカー', 'Shaker', [
        'description' => '@d1@で使う。'."\n".'2 行目', 'youtube' => 'abcdefghijk', 'youtube_start_seconds' => 61,
        'image' => 'shaker.jpg', 'image_txt' => '出典', 'image_url' => 'https://example.com/',
    ]);

    $this->getJson("/api/dictionaries/{$term->id}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $term->id,
            'name' => 'シェイカー',
            'name_eng' => 'Shaker',
            'category' => ['id' => $category->id, 'name' => '器具', 'name_eng' => 'Tool'],
            'kind' => 'other',
            'method_id' => null,
            'description' => '@d1@で使う。'."\n".'2 行目',
            'youtube' => 'abcdefghijk',
            'youtube_start_seconds' => 61,
            'image' => 'shaker.jpg',
            'image_txt' => '出典',
            'image_url' => 'https://example.com/',
        ]]);
});

it('TC-46-05: 技法に結び付いた用語は、種類が method で、技法の ID を返す(用語の ID と技法の ID が違っても)', function () {
    $category = dictionaryCategory('製法', 'Method', 1);
    dictionaryTerm($category, 'ビルド', 'Build');
    $shake = dictionaryTerm($category, 'シェイク', 'Shake');
    // 用語の ID と技法の ID をずらす(旧は同じ ID であることに頼っていた)
    Method::factory()->create(['name' => 'ビルド', 'name_eng' => 'Build']);
    Method::factory()->create(['name' => 'ステア', 'name_eng' => 'Stir']);
    $method = Method::factory()->create([
        'id' => $shake->id + 1000, 'name' => 'シェイク', 'name_eng' => 'Shake', 'dictionary_id' => $shake->id,
    ]);
    expect($method->id)->not->toBe($shake->id);

    $this->getJson("/api/dictionaries/{$shake->id}")
        ->assertOk()
        ->assertJsonPath('data.kind', 'method')
        ->assertJsonPath('data.method_id', $method->id);
});

it('TC-46-06: 分類「グラス」の用語は、種類が glass で、method_id は null', function () {
    $glass = dictionaryCategory('グラス', 'Glass', DictionaryCategory::GLASS_ID);
    $term = dictionaryTerm($glass, 'カクテル・グラス', 'Cocktail glass');

    $this->getJson("/api/dictionaries/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.kind', 'glass')
        ->assertJsonPath('data.method_id', null)
        ->assertJsonPath('data.category.name', 'グラス');
});

it('TC-46-07: 技法に結び付いていない用語は、分類が「製法」でも種類が other', function () {
    $category = dictionaryCategory('製法', 'Method', 1);
    $term = dictionaryTerm($category, 'ブレンド', 'Blend');

    $this->getJson("/api/dictionaries/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.kind', 'other')
        ->assertJsonPath('data.method_id', null);
});

it('TC-46-08: 動画・画像の無い用語は、その項目が null', function () {
    $term = dictionaryTerm(dictionaryCategory('単位', 'Unit'), 'ダッシュ', 'Dash');

    $this->getJson("/api/dictionaries/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.youtube', null)
        ->assertJsonPath('data.youtube_start_seconds', null)
        ->assertJsonPath('data.image', null)
        ->assertJsonPath('data.image_txt', null)
        ->assertJsonPath('data.image_url', null);
});

it('TC-46-09: 無い ID の詳細は 404', function () {
    $term = dictionaryTerm(dictionaryCategory('単位', 'Unit'), 'ダッシュ', 'Dash');

    $this->getJson('/api/dictionaries/'.($term->id + 1))->assertNotFound();
});

it('TC-46-10: 数字でない ID・桁があふれる ID・0 の詳細は 404(500 にしない)', function (string $id) {
    $this->getJson("/api/dictionaries/{$id}")->assertNotFound();
})->with([
    '数字でない' => ['abc'],
    '桁があふれる' => ['9999999999999999999'],
    '0' => ['0'],
]);
