<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 管理 API の銘柄の名前・英名の重なりの問い合わせ(#632)。旧の DetailEditController@duplicate_check の銘柄の分の置き換え。
// 比べ方は保存の後の warnings(AdminBrandWriter::duplicateWarnings。#314)と同じ。
// 旧と変えたところは #632 の本文の「旧との差分」。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * 名前・英名の銘柄を作る。検索用の名前は、保存の API と同じく名前から作る。
 */
function brandDuplicateMake(string $name, string $nameEng, ?Category $category = null): Brand
{
    return Brand::factory()->create([
        ...($category !== null ? ['category_id' => $category->id] : []),
        'name' => $name,
        'name_search' => NameNormalizer::normalize($name),
        'name_eng' => $nameEng,
    ]);
}

/**
 * 重なりを問い合わせる。
 *
 * @param  array<array-key, mixed>  $query
 * @return TestResponse<JsonResponse>
 */
function brandDuplicateGet(TestCase $test, array $query): TestResponse
{
    return $test->getJson('/api/admin/brands/duplicates?'.http_build_query($query));
}

const BRAND_DUPLICATE_NAME = ['field' => 'name', 'message' => '同じ名前が存在しています'];
const BRAND_DUPLICATE_NAME_ENG = ['field' => 'name_eng', 'message' => '同じ名前が存在しています'];

it('TC-632-01: ログインしていないと 401', function () {
    auth()->logout();

    $this->getJson('/api/admin/brands/duplicates?name=バカルディ')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('TC-632-02: 名前も英名も無い・名前か英名が 256 文字・文字列でない・exclude_id が 1 以上の整数でないと 422', function (array $query, array $errors) {
    brandDuplicateGet($this, $query)->assertUnprocessable()->assertOnlyJsonValidationErrors($errors);
})->with([
    '名前も英名も無い' => [[], ['name', 'name_eng']],
    '名前も英名も空' => [['name' => '', 'name_eng' => ''], ['name', 'name_eng']],
    '名前が 256 文字' => [['name' => str_repeat('あ', 256)], ['name']],
    '英名が 256 文字' => [['name_eng' => str_repeat('a', 256)], ['name_eng']],
    '名前が配列' => [['name' => ['バカルディ']], ['name']],
    '英名が配列' => [['name_eng' => ['Bacardi']], ['name_eng']],
    'exclude_id が文字' => [['name' => 'バカルディ', 'exclude_id' => 'a'], ['exclude_id']],
    'exclude_id が 0' => [['name' => 'バカルディ', 'exclude_id' => 0], ['exclude_id']],
    'exclude_id が小数' => [['name' => 'バカルディ', 'exclude_id' => '1.5'], ['exclude_id']],
    'exclude_id が整数の範囲を超える' => [['name' => 'バカルディ', 'exclude_id' => '99999999999999999999'], ['exclude_id']],
]);

it('TC-632-03: 名前と 255 文字ちょうどの名前・英名は受け、重なりが無ければ警告は空', function () {
    brandDuplicateMake('バカルディ', 'Bacardi');

    brandDuplicateGet($this, ['name' => 'ハバナクラブ', 'name_eng' => 'Havana Club'])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
    brandDuplicateGet($this, ['name' => str_repeat('あ', 255), 'name_eng' => str_repeat('a', 255)])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
});

it('TC-632-04: 名前は検索用の名前でそろえて比べ、区切り(・ . 空白)の有無・全角の英数字の違いだけなら重なりあり', function (string $stored, string $input) {
    brandDuplicateMake($stored, 'Stored');

    brandDuplicateGet($this, ['name' => $input])
        ->assertOk()
        ->assertExactJson(['warnings' => [BRAND_DUPLICATE_NAME]]);
})->with([
    '同じ名前' => ['バカルディ', 'バカルディ'],
    '中黒の有無' => ['ジョニーウォーカー', 'ジョニー・ウォーカー'],
    'ピリオドの有無' => ['Mr.ボストン', 'Mrボストン'],
    '半角の空白の有無' => ['ハバナ クラブ', 'ハバナクラブ'],
    '全角の英数字' => ['ゴードン 47', 'ゴードン４７'],
]);

it('TC-632-05: 名前は検索用の名前で揃わない違い(全角の空白・別の文字)なら重なりなし', function (string $stored, string $input) {
    brandDuplicateMake($stored, 'Stored');

    brandDuplicateGet($this, ['name' => $input])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
})->with([
    '全角の空白は消さない' => ['ハバナクラブ', 'ハバナ　クラブ'],
    '別の名前' => ['バカルディ', 'バカルディ 8'],
]);

it('TC-632-06: 英名はそのまま比べ、同じ英名なら重なりあり。区切りの違う英名は重なりなし', function () {
    brandDuplicateMake('バカルディ', 'Bacardi Superior');

    brandDuplicateGet($this, ['name_eng' => 'Bacardi Superior'])
        ->assertOk()
        ->assertExactJson(['warnings' => [BRAND_DUPLICATE_NAME_ENG]]);
    brandDuplicateGet($this, ['name_eng' => 'BacardiSuperior'])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);
});

it('TC-632-07: 名前・英名の両方を送ると項目ごとに比べ、別の銘柄・別の所属でも重なりありを名前・英名の順に返す', function () {
    $a = Category::factory()->create();
    $b = Category::factory()->create();
    brandDuplicateMake('バカルディ', 'Bacardi', $a);
    brandDuplicateMake('ハバナクラブ', 'Havana Club', $b);

    brandDuplicateGet($this, ['name' => 'ハバナクラブ', 'name_eng' => 'Bacardi'])
        ->assertOk()
        ->assertExactJson(['warnings' => [BRAND_DUPLICATE_NAME, BRAND_DUPLICATE_NAME_ENG]]);
    brandDuplicateGet($this, ['name' => 'ハバナクラブ', 'name_eng' => 'Other'])
        ->assertOk()
        ->assertExactJson(['warnings' => [BRAND_DUPLICATE_NAME]]);
});

it('TC-632-08: exclude_id の銘柄(編集している銘柄)自身は数えず、ほかに同じものがあれば重なりあり', function () {
    $mine = brandDuplicateMake('バカルディ', 'Bacardi');

    brandDuplicateGet($this, ['name' => 'バカルディ', 'name_eng' => 'Bacardi', 'exclude_id' => $mine->id])
        ->assertOk()
        ->assertExactJson(['warnings' => []]);

    brandDuplicateMake('バカルディ', 'Bacardi');
    brandDuplicateGet($this, ['name' => 'バカルディ', 'name_eng' => 'Bacardi', 'exclude_id' => $mine->id])
        ->assertOk()
        ->assertExactJson(['warnings' => [BRAND_DUPLICATE_NAME, BRAND_DUPLICATE_NAME_ENG]]);
});

it('TC-632-09: exclude_id を送らない(新規)・無い銘柄の ID なら、すべての銘柄と比べる', function (bool $missingId) {
    $brand = brandDuplicateMake('バカルディ', 'Bacardi');

    $query = ['name' => 'バカルディ'];
    if ($missingId) {
        $query['exclude_id'] = $brand->id + 1000;
    }
    brandDuplicateGet($this, $query)
        ->assertOk()
        ->assertExactJson(['warnings' => [BRAND_DUPLICATE_NAME]]);
})->with(['exclude_id を送らない' => false, '無い ID' => true]);

it('TC-632-10: 片方だけ送るか、片方が空なら、その項目は比べない', function (array $query, array $warnings) {
    brandDuplicateMake('バカルディ', 'Bacardi');

    brandDuplicateGet($this, $query)
        ->assertOk()
        ->assertExactJson(['warnings' => $warnings]);
})->with([
    '名前だけ(英名は送らない)' => [['name' => 'ハバナクラブ'], []],
    '英名が空' => [['name' => 'ハバナクラブ', 'name_eng' => ''], []],
    '英名だけ(名前は送らない)' => [['name_eng' => 'Havana Club'], []],
    '名前が空' => [['name' => '', 'name_eng' => 'Bacardi'], [BRAND_DUPLICATE_NAME_ENG]],
]);
