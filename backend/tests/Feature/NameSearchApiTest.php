<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Method;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 名前の横断検索の API(GET /api/search?q=…。#172)。
// 期待値は、旧の SearchController@fromName・Search::generate_search_array・Search::find_Jap_or_Eng の挙動から置く。
// 旧と変えたところは docs/decisions/name-search.md の「旧との差分」。

uses(RefreshDatabase::class)->group('db');

/**
 * 名前を決めて木のノードを作る。name_search は旧と同じく名前を NameNormalizer でそろえた値にする。
 */
function nameSearchNode(?Category $parent, string $name, string $nameEng): Category
{
    return app(CategoryTree::class)->addNode($parent, Category::factory()->make([
        'name' => $name, 'name_search' => NameNormalizer::normalize($name), 'name_eng' => $nameEng,
    ])->getAttributes());
}

/**
 * 名前を決めてカクテルを作る。
 *
 * @param  array<string, mixed>  $attrs
 */
function nameSearchCocktail(string $name, string $nameEng, array $attrs = []): Cocktail
{
    return Cocktail::factory()->create([
        'name' => $name, 'name_search' => NameNormalizer::normalize($name), 'name_eng' => $nameEng, ...$attrs,
    ]);
}

/**
 * 名前を決めて銘柄を作る。
 *
 * @param  array<string, mixed>  $attrs
 */
function nameSearchBrand(string $name, string $nameEng, array $attrs = []): Brand
{
    return Brand::factory()->create([
        'name' => $name, 'name_search' => NameNormalizer::normalize($name), 'name_eng' => $nameEng, ...$attrs,
    ]);
}

/**
 * @param  list<Model>  $models
 * @return list<mixed>
 */
function nameSearchIds(array $models): array
{
    return array_map(fn (Model $model): mixed => $model->getKey(), $models);
}

/**
 * @return TestResponse<JsonResponse>
 */
function nameSearch(TestCase $test, string $q): TestResponse
{
    return $test->getJson('/api/search?'.http_build_query(['q' => $q]));
}

/**
 * 応答の種類ごとの ID を、返った順で確かめる。
 *
 * @param  list<Cocktail>  $cocktails
 * @param  list<Category>  $nodes
 * @param  list<Brand>  $brands
 * @param  TestResponse<JsonResponse>  $response
 */
function assertNameSearch(TestResponse $response, array $cocktails, array $nodes, array $brands): void
{
    $response->assertOk()
        ->assertJsonPath('cocktails.*.id', nameSearchIds($cocktails))
        ->assertJsonPath('nodes.*.id', nameSearchIds($nodes))
        ->assertJsonPath('brands.*.id', nameSearchIds($brands));
}

it('TC-172-01: 英数字だけの語は、英名と検索用の名前の部分一致で、カクテル・ノード・銘柄を返す', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    $gin = nameSearchNode($liquor, 'ジン', 'Gin');
    $tonic = nameSearchNode($liquor, 'トニック', 'Tonic');
    $byEng = nameSearchCocktail('ジン・トニック', 'Gin & Tonic');
    $bySearch = nameSearchCocktail('Ginger Ale Highball', 'Shandy Gaff');
    nameSearchCocktail('モスコミュール', 'Moscow Mule');
    $brand = nameSearchBrand('ゴードン', "Gordon's Gin", ['category_id' => $gin->id]);
    nameSearchBrand('ウィルキンソン', 'Wilkinson', ['category_id' => $tonic->id]);

    // 英名(大文字小文字を問わない)と、検索用の名前の両方を探す
    assertNameSearch(nameSearch($this, 'gin'), [$byEng, $bySearch], [$gin], [$brand]);
});

it('TC-172-02: 英数字だけでない語は、検索用の名前だけを探し、英名は見ない', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    $gin = nameSearchNode($liquor, 'ジン', 'Gin');
    $dryGin = nameSearchNode($gin, 'ドライ・ジン', 'Dry Gin');
    nameSearchNode($liquor, 'ラム', 'Rum');
    $cocktail = nameSearchCocktail('ジン・トニック', 'Gin & Tonic');
    nameSearchCocktail('マティーニ', 'Martini');
    $brand = nameSearchBrand('ボンベイ・サファイア ジン', 'Bombay Sapphire', ['category_id' => $dryGin->id]);

    assertNameSearch(nameSearch($this, 'ジン'), [$cocktail], [$gin, $dryGin], [$brand]);
    // 全角の英数字は英数字だけに数えない(旧の ctype_alnum)ので、半角にそろえた語で検索用の名前だけを探す
    assertNameSearch(nameSearch($this, 'ｇｉｎ'), [], [], []);
});

it('TC-172-03: 空白で分けた語は、どれかに当たれば返す', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    $gin = nameSearchNode($liquor, 'ジン', 'Gin');
    $rum = nameSearchNode($liquor, 'ラム', 'Rum');
    nameSearchNode($liquor, 'テキーラ', 'Tequila');
    $ginTonic = nameSearchCocktail('ジン・トニック', 'Gin & Tonic');
    $daiquiri = nameSearchCocktail('ラム・ダイキリ', 'Daiquiri');
    nameSearchCocktail('マルガリータ', 'Margarita');

    // 半角・全角の空白のどちらでも分ける
    assertNameSearch(nameSearch($this, 'ジン ラム'), [$ginTonic, $daiquiri], [$gin, $rum], []);
    assertNameSearch(nameSearch($this, 'ジン　ラム'), [$ginTonic, $daiquiri], [$gin, $rum], []);
});

it('TC-172-04: 空白を含む英字の入力は、英数字だけに数えず、検索用の名前だけを探す', function () {
    nameSearchCocktail('ジン・トニック', 'Gin Tonic');
    $bySearch = nameSearchCocktail('Gin Buck', 'Gin Buck');

    // 旧は空白で分ける前の入力全体で英数字だけかを見ていた。英名だけに当たるカクテルは出ない
    assertNameSearch(nameSearch($this, 'gin tonic'), [$bySearch], [], []);
});

it('TC-172-05: 語は検索用の名前と同じ形にそろえてから探す', function () {
    $cocktail = nameSearchCocktail('ジン・トニック', 'Gin & Tonic');
    nameSearchCocktail('ジン・フィズ', 'Gin Fizz');

    // 中黒を消して詰める(ジントニック)。全角の英数字は半角にする
    assertNameSearch(nameSearch($this, 'ジン・トニック'), [$cocktail], [], []);
    $abc = nameSearchCocktail('ABC2', 'A.B.C.');
    assertNameSearch(nameSearch($this, 'ＡＢＣ２'), [$abc], [], []);
});

it('TC-172-06: 根(旧の種別)は返さず、2 段目と 3 段目以降のノードはどちらも返す', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    nameSearchNode(null, 'その他の酒', 'Other Liquor');
    $whisky = nameSearchNode($liquor, 'ウイスキー酒', 'Whisky');
    $deep = nameSearchNode(nameSearchNode($whisky, 'スコッチ', 'Scotch'), '酒精強化', 'Fortified');

    assertNameSearch(nameSearch($this, '酒'), [], [$whisky, $deep], []);
});

it('TC-172-07: 下書きのカクテルは、英数字の入力で英名に当たっても返さない', function () {
    $published = nameSearchCocktail('サンダウン', 'Sundown');
    nameSearchCocktail('サンダウン改', 'Sundown', ['is_draft' => true]);
    nameSearchCocktail('Sundown2', 'Sunset', ['is_draft' => true]);

    assertNameSearch(nameSearch($this, 'Sundown'), [$published], [], []);
    assertNameSearch(nameSearch($this, 'サンダウン'), [$published], [], []);
});

it('TC-172-08: 入力が無い・空・空白だけなら 422', function (?string $q) {
    $url = $q === null ? '/api/search' : '/api/search?'.http_build_query(['q' => $q]);

    $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('q');
})->with([
    '無い' => [null],
    '空' => [''],
    '半角の空白だけ' => ['   '],
    // 前後の空白を除くミドルウェア(TrimStrings)が全角の空白も除く
    '全角の空白だけ' => ['　'],
]);

it('TC-172-09: 記号を含む入力も受け付け、% と _ は LIKE の特別な文字のまま効く(旧と同じ)', function () {
    $abc = nameSearchCocktail('ABC', 'A.B.C.');
    $gordon = nameSearchCocktail("Gordon's", 'Gordon');
    $martini = nameSearchCocktail('マティーニ', 'Martini');

    // ピリオドは語をそろえるときに消える(ABC)。英数字だけではないので検索用の名前だけを探す
    assertNameSearch(nameSearch($this, 'A.B.C'), [$abc], [], []);
    assertNameSearch(nameSearch($this, "Gordon's"), [$gordon], [], []);
    // 旧は語を LIKE に入れるときに % と _ をエスケープしていなかったので、すべてに当たる
    assertNameSearch(nameSearch($this, '%'), [$abc, $gordon, $martini], [], []);
    assertNameSearch(nameSearch($this, '_'), [$abc, $gordon, $martini], [], []);
});

it('TC-172-10: 255 文字までは受け付け、256 文字は 422', function () {
    nameSearch($this, str_repeat('あ', 255))->assertOk();
    nameSearch($this, str_repeat('あ', 256))->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('TC-172-11: 中黒だけの入力は、語が無くなり、絞らずにすべてを返す(旧と同じ)', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    $gin = nameSearchNode($liquor, 'ジン', 'Gin');
    $cocktail = nameSearchCocktail('マティーニ', 'Martini');
    nameSearchCocktail('下書き', 'Draft', ['is_draft' => true]);
    $brand = nameSearchBrand('ゴードン', 'Gordon', ['category_id' => $gin->id]);

    assertNameSearch(nameSearch($this, '・'), [$cocktail], [$gin], [$brand]);
});

it('TC-172-12: 種類ごとに ID の順で返す', function () {
    $second = nameSearchCocktail('ジン・リッキー', 'Gin Rickey');
    $first = nameSearchCocktail('ジン・トニック', 'Gin Tonic');
    // ID の順と名前の順(トニックが先)を逆にして、名前の順でないことを確かめる
    expect($first->id)->toBeGreaterThan($second->id);

    assertNameSearch(nameSearch($this, 'ジン'), [$second, $first], [], []);
});

it('TC-172-13: 1 件ごとに、一覧に出す項目を返す', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    $gin = nameSearchNode($liquor, 'ジン', 'Gin');
    $dryGin = nameSearchNode($gin, 'ドライ・ジン', 'Dry Gin');
    $build = Method::factory()->create(['name' => 'ビルド', 'name_eng' => 'Build']);
    $cocktail = nameSearchCocktail('ジン・トニック', 'Gin & Tonic', [
        'base_category_id' => $gin->id, 'method_id' => $build->id, 'volume' => 12,
        'sweet_degree' => 3, 'rate' => null, 'youtube' => null, 'is_major' => true,
    ]);
    $brand = nameSearchBrand('ゴードン ジン', "Gordon's Gin", [
        'category_id' => $dryGin->id, 'volume' => 37.5, 'youtube' => 'abc123',
    ]);

    nameSearch($this, 'ジン')->assertOk()
        ->assertJsonPath('cocktails.0', [
            'id' => $cocktail->id,
            'name' => 'ジン・トニック',
            'name_eng' => 'Gin & Tonic',
            'is_major' => true,
            'base' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
            'method' => ['id' => $build->id, 'name' => 'ビルド'],
            'recipe_count' => 0,
            'strength' => 12,
            'sweet_degree' => 3,
            'rate' => null,
            'youtube' => null,
            'updated_at' => $cocktail->updated_at?->toJSON(),
        ])
        ->assertJsonPath('nodes', [
            [
                'id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin',
                'parent' => ['id' => $liquor->id, 'name' => '蒸留酒', 'name_eng' => 'Spirits'],
            ],
            [
                'id' => $dryGin->id, 'name' => 'ドライ・ジン', 'name_eng' => 'Dry Gin',
                'parent' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
            ],
        ])
        ->assertJsonPath('brands', [[
            'id' => $brand->id,
            'name' => 'ゴードン ジン',
            'name_eng' => "Gordon's Gin",
            'volume' => 37.5,
            'youtube' => 'abc123',
            'category' => ['id' => $dryGin->id, 'name' => 'ドライ・ジン', 'name_eng' => 'Dry Gin'],
        ]]);
});
