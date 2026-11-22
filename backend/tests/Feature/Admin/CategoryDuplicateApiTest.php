<?php

use App\Models\Category;
use App\Models\User;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 管理 API のカテゴリ木のノードの名前・英名の重なりの問い合わせ(#673)。旧の DetailEditController@duplicate_check の
// カテゴリ・ミドルの分の置き換え。比べ方は保存の API(AdminCategoryWriter。#121)と同じ。
// 旧と変えたところは #673 の本文の「旧との差分」。木は CategoryTree だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * $parent の下(null なら根)に、名前・英名のノードを作る。検索用の名前は、保存の API と同じく名前から作る。
 */
function categoryDuplicateAdd(?Category $parent, string $name, string $nameEng): Category
{
    return app(CategoryTree::class)->addNode($parent, Category::factory()->make([
        'name' => $name,
        'name_search' => NameNormalizer::normalize($name),
        'name_eng' => $nameEng,
    ])->getAttributes());
}

/**
 * テストに使う木。
 *
 * 洋酒(根。Western Liquor)
 * ├ ラム(Rum)
 * │  └ ホワイトラム(White Rum)
 * └ ジン(Gin)
 * その他(根。Other)
 *
 * @return array<string, Category>
 */
function categoryDuplicateTree(): array
{
    $n = [];
    $n['洋酒'] = categoryDuplicateAdd(null, '洋酒', 'Western Liquor');
    $n['ラム'] = categoryDuplicateAdd($n['洋酒'], 'ラム', 'Rum');
    $n['ホワイトラム'] = categoryDuplicateAdd($n['ラム'], 'ホワイトラム', 'White Rum');
    $n['ジン'] = categoryDuplicateAdd($n['洋酒'], 'ジン', 'Gin');
    $n['その他'] = categoryDuplicateAdd(null, 'その他', 'Other');

    return $n;
}

/**
 * 重なりを問い合わせる。
 *
 * @param  array<array-key, mixed>  $query
 * @return TestResponse<JsonResponse>
 */
function categoryDuplicateGet(TestCase $test, array $query): TestResponse
{
    return $test->getJson('/api/admin/categories/duplicates?'.http_build_query($query));
}

const CATEGORY_DUPLICATE_NAME = ['field' => 'name', 'message' => '同じ名前が存在しています'];
const CATEGORY_DUPLICATE_NAME_ENG = ['field' => 'name_eng', 'message' => '同じ名前が存在しています'];

it('TC-673-01: ログインしていないと 401', function () {
    auth()->logout();

    $this->getJson('/api/admin/categories/duplicates?name=ラム')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('TC-673-02: 名前も英名も無い・名前か英名が 256 文字・文字列でない・parent_id と exclude_id が 1 以上の整数でない・無い親は 422', function (array $query, array $errors) {
    categoryDuplicateTree();

    categoryDuplicateGet($this, $query)->assertUnprocessable()->assertOnlyJsonValidationErrors($errors);
})->with([
    '名前も英名も無い' => [[], ['name', 'name_eng']],
    '名前も英名も空' => [['name' => '', 'name_eng' => ''], ['name', 'name_eng']],
    '名前が 256 文字' => [['name' => str_repeat('あ', 256)], ['name']],
    '英名が 256 文字' => [['name_eng' => str_repeat('a', 256)], ['name_eng']],
    '名前が配列' => [['name' => ['ラム']], ['name']],
    '英名が配列' => [['name_eng' => ['Rum']], ['name_eng']],
    'parent_id が文字' => [['name' => 'ラム', 'parent_id' => 'a'], ['parent_id']],
    'parent_id が 0' => [['name' => 'ラム', 'parent_id' => 0], ['parent_id']],
    'parent_id が小数' => [['name' => 'ラム', 'parent_id' => '1.5'], ['parent_id']],
    'parent_id が整数の範囲を超える' => [['name' => 'ラム', 'parent_id' => '99999999999999999999'], ['parent_id']],
    'parent_id が配列' => [['name' => 'ラム', 'parent_id' => [1]], ['parent_id']],
    'parent_id が無いノード' => [['name' => 'ラム', 'parent_id' => 999999], ['parent_id']],
    'exclude_id が文字' => [['name' => 'ラム', 'exclude_id' => 'a'], ['exclude_id']],
    'exclude_id が 0' => [['name' => 'ラム', 'exclude_id' => 0], ['exclude_id']],
    'exclude_id が小数' => [['name' => 'ラム', 'exclude_id' => '1.5'], ['exclude_id']],
    'exclude_id が整数の範囲を超える' => [['name' => 'ラム', 'exclude_id' => '99999999999999999999'], ['exclude_id']],
]);

it('TC-673-03: 名前と 255 文字ちょうどの名前・英名は受け、重なりが無ければ警告は空', function () {
    $n = categoryDuplicateTree();

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => 'ウイスキー', 'name_eng' => 'Whisky'])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => str_repeat('あ', 255), 'name_eng' => str_repeat('a', 255)])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
});

it('TC-673-04: 名前は検索用の名前でそろえて比べ、同じ親の下に区切り(・ . 空白)の有無・全角の英数字の違いだけの名前があれば重なりあり', function (string $stored, string $input) {
    $n = categoryDuplicateTree();
    categoryDuplicateAdd($n['洋酒'], $stored, 'Stored');

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => $input])
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME]]);
})->with([
    '同じ名前' => ['テキーラ', 'テキーラ'],
    '中黒の有無' => ['ドライジン', 'ドライ・ジン'],
    'ピリオドの有無' => ['St.ジャーメイン', 'Stジャーメイン'],
    '半角の空白の有無' => ['ホワイト リカー', 'ホワイトリカー'],
    '全角の英数字' => ['ウォッカ 100', 'ウォッカ１００'],
]);

it('TC-673-05: 名前は検索用の名前で揃わない違い(全角の空白・別の文字)なら重なりなし', function (string $stored, string $input) {
    $n = categoryDuplicateTree();
    categoryDuplicateAdd($n['洋酒'], $stored, 'Stored');

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => $input])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
})->with([
    '全角の空白は消さない' => ['ホワイトリカー', 'ホワイト　リカー'],
    '別の名前' => ['テキーラ', 'テキーラ 2'],
]);

it('TC-673-06: 英名はそのまま比べ、同じ親の下に同じ英名があれば重なりあり。大文字・小文字や区切りの違う英名は重なりなし', function () {
    $n = categoryDuplicateTree();

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name_eng' => 'Rum'])
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME_ENG]]);
    categoryDuplicateGet($this, ['parent_id' => $n['ラム']->id, 'name_eng' => 'White Rum'])
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME_ENG]]);
    categoryDuplicateGet($this, ['parent_id' => $n['ラム']->id, 'name_eng' => 'WhiteRum'])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
});

it('TC-673-07: 別の親の下のノード(孫・親自身・ほかの根の子)は数えない', function (string $parent, array $query) {
    $n = categoryDuplicateTree();
    categoryDuplicateAdd($n['その他'], 'テキーラ', 'Tequila');

    categoryDuplicateGet($this, ['parent_id' => $n[$parent]->id, ...$query])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
})->with([
    '孫(ホワイトラム)は洋酒の子ではない' => ['洋酒', ['name' => 'ホワイトラム', 'name_eng' => 'White Rum']],
    '親自身(ラム)はラムの子ではない' => ['ラム', ['name' => 'ラム', 'name_eng' => 'Rum']],
    'ほかの根(その他)の子' => ['洋酒', ['name' => 'テキーラ', 'name_eng' => 'Tequila']],
]);

it('TC-673-08: parent_id を送らないか空なら根どうしで比べ、根でないノードは数えない', function (array $query, array $warnings) {
    categoryDuplicateTree();

    categoryDuplicateGet($this, $query)
        ->assertOk()
        ->assertExactJson(['warnings' => $warnings]);
})->with([
    '根の名前・英名(parent_id を送らない)' => [['name' => '洋酒', 'name_eng' => 'Other'], [CATEGORY_DUPLICATE_NAME, CATEGORY_DUPLICATE_NAME_ENG]],
    '根の名前(parent_id が空)' => [['parent_id' => '', 'name' => 'その他'], [CATEGORY_DUPLICATE_NAME]],
    '根でないノードの名前・英名' => [['name' => 'ラム', 'name_eng' => 'Gin'], []],
]);

it('TC-673-09: exclude_id のノード(編集しているノード)自身は数えず、同じ親の下にほかに同じものがあれば重なりあり', function () {
    $n = categoryDuplicateTree();

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => 'ラム', 'name_eng' => 'Rum', 'exclude_id' => $n['ラム']->id])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);

    categoryDuplicateAdd($n['洋酒'], 'ラム', 'Rum');
    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => 'ラム', 'name_eng' => 'Rum', 'exclude_id' => $n['ラム']->id])
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME, CATEGORY_DUPLICATE_NAME_ENG]]);
});

it('TC-673-10: exclude_id を送らない(新規)・無いノードの ID なら、同じ親の下のすべてのノードと比べる', function (bool $missingId) {
    $n = categoryDuplicateTree();

    $query = ['parent_id' => $n['洋酒']->id, 'name' => 'ジン'];
    if ($missingId) {
        $query['exclude_id'] = $n['その他']->id + 1000;
    }
    categoryDuplicateGet($this, $query)
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME]]);
})->with(['exclude_id を送らない' => false, '無い ID' => true]);

it('TC-673-11: 名前・英名の両方を送ると項目ごとに比べ、別のノードとの重なりでも名前・英名の順に返す', function () {
    $n = categoryDuplicateTree();

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => 'ジン', 'name_eng' => 'Rum'])
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME, CATEGORY_DUPLICATE_NAME_ENG]]);
    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, 'name' => 'ジン', 'name_eng' => 'Other'])
        ->assertOk()
        ->assertExactJson(['warnings' => [CATEGORY_DUPLICATE_NAME]]);
});

it('TC-673-12: 片方だけ送るか、片方が空なら、その項目は比べない', function (array $query, array $warnings) {
    $n = categoryDuplicateTree();

    categoryDuplicateGet($this, ['parent_id' => $n['洋酒']->id, ...$query])
        ->assertOk()
        ->assertExactJson(['warnings' => $warnings]);
})->with([
    '名前だけ(英名は送らない)' => [['name' => 'ウイスキー'], []],
    '英名が空' => [['name' => 'ウイスキー', 'name_eng' => ''], []],
    '英名だけ(名前は送らない)' => [['name_eng' => 'Whisky'], []],
    '名前が空' => [['name' => '', 'name_eng' => 'Gin'], [CATEGORY_DUPLICATE_NAME_ENG]],
]);
