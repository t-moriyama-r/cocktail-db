<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
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

it('TC-172-09: 記号を含む入力も受け付け、% と _ はふつうの文字として探す', function () {
    $abc = nameSearchCocktail('ABC', 'A.B.C.');
    $gordon = nameSearchCocktail("Gordon's", 'Gordon');
    nameSearchCocktail('マティーニ', 'Martini');

    // ピリオドは語をそろえるときに消える(ABC)。英数字だけではないので検索用の名前だけを探す
    assertNameSearch(nameSearch($this, 'A.B.C'), [$abc], [], []);
    assertNameSearch(nameSearch($this, "Gordon's"), [$gordon], [], []);
    // 旧は % と _ がすべてに当たった。新はふつうの文字なので、名前に含むものだけに当たる
    $percent = nameSearchCocktail('100%ジュース', 'Hundred');
    $under = nameSearchCocktail('ア_イ', 'A_I');
    assertNameSearch(nameSearch($this, '%'), [$percent], [], []);
    assertNameSearch(nameSearch($this, '_'), [$under], [], []);
});

it('TC-172-10: 255 文字までは受け付け、256 文字は 422', function () {
    nameSearch($this, str_repeat('あ', 255))->assertOk();
    nameSearch($this, str_repeat('あ', 256))->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('TC-172-11: 中黒だけの入力は、語が無くなるので 422(旧は絞らずにすべてを返した)', function () {
    nameSearchCocktail('マティーニ', 'Martini');

    nameSearch($this, '・')->assertUnprocessable()->assertJsonValidationErrors('q');
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
            // 名前で当たったので、当たった別名は無い(#82)
            'matched_alias' => null,
        ])
        ->assertJsonPath('nodes', [
            [
                'id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin',
                'parent' => ['id' => $liquor->id, 'name' => '蒸留酒', 'name_eng' => 'Spirits'],
                'path' => [
                    ['id' => $liquor->id, 'name' => '蒸留酒', 'name_eng' => 'Spirits'],
                    ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
                ],
            ],
            [
                'id' => $dryGin->id, 'name' => 'ドライ・ジン', 'name_eng' => 'Dry Gin',
                'parent' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
                'path' => [
                    ['id' => $liquor->id, 'name' => '蒸留酒', 'name_eng' => 'Spirits'],
                    ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
                    ['id' => $dryGin->id, 'name' => 'ドライ・ジン', 'name_eng' => 'Dry Gin'],
                ],
            ],
        ])
        ->assertJsonPath('brands', [[
            'id' => $brand->id,
            'name' => 'ゴードン ジン',
            'name_eng' => "Gordon's Gin",
            'volume' => 37.5,
            'youtube' => 'abc123',
            'category' => ['id' => $dryGin->id, 'name' => 'ドライ・ジン', 'name_eng' => 'Dry Gin'],
            'path' => [
                ['id' => $liquor->id, 'name' => '蒸留酒', 'name_eng' => 'Spirits'],
                ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
                ['id' => $dryGin->id, 'name' => 'ドライ・ジン', 'name_eng' => 'Dry Gin'],
            ],
        ]]);
});

it('TC-52-01: ノードと銘柄の path は、深い木でも根から順に、すべての祖先と末尾の自分(銘柄なら所属ノード)を返す', function () {
    $liquor = nameSearchNode(null, '蒸留酒', 'Spirits');
    $rum = nameSearchNode($liquor, 'ラム', 'Rum');
    $white = nameSearchNode($rum, 'ホワイトラム', 'White Rum');
    $cuban = nameSearchNode($white, 'キューバンホワイトラム', 'Cuban White Rum');
    nameSearchBrand('ハバナクラブ ラム', 'Havana Club', ['category_id' => $cuban->id]);
    $ref = fn (Category $node): array => ['id' => $node->id, 'name' => $node->name, 'name_eng' => $node->name_eng];

    nameSearch($this, 'キューバン')->assertOk()
        ->assertJsonPath('nodes.0.path', [$ref($liquor), $ref($rum), $ref($white), $ref($cuban)]);
    nameSearch($this, 'ハバナ')->assertOk()
        ->assertJsonPath('brands.0.path', [$ref($liquor), $ref($rum), $ref($white), $ref($cuban)]);
});

// 材料名からカクテルを探す欄(ingredient_matches。#173)。
// 期待値は、旧の CocktailFactory::intelligenceSearch の挙動から置く。
// 旧と変えたところは docs/decisions/name-search.md の「旧との差分」(#173 の節)。

/**
 * カクテルに材料を 1 行足す。$use がノードなら銘柄を決めない材料、銘柄なら銘柄の所属ノードでその銘柄を決めた材料。
 */
function nameSearchUses(Cocktail $cocktail, Category|Brand $use, bool $optional = false): Cocktail
{
    $position = CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->count() + 1;
    $factory = CocktailIngredient::factory();
    $factory = $use instanceof Brand ? $factory->forBrand($use) : $factory->state(['category_id' => $use->id]);
    $factory->create(['cocktail_id' => $cocktail->id, 'position' => $position, 'is_optional' => $optional]);

    return $cocktail;
}

/**
 * ingredient_matches の ID を、返った順で確かめる。
 *
 * @param  list<Cocktail>  $cocktails
 * @param  TestResponse<JsonResponse>  $response
 */
function assertIngredientMatches(TestResponse $response, array $cocktails): void
{
    $response->assertOk()->assertJsonPath('ingredient_matches.*.id', nameSearchIds($cocktails));
}

/**
 * 洋酒(根) > リキュール > フルーツ系 > メロンリキュール > 緑のメロンリキュール の木と、レモン(ソフトドリンクの根の下)を作る。
 *
 * @return array<string, Category>
 */
function ingredientSearchTree(): array
{
    $liquor = nameSearchNode(null, '洋酒', 'Liquor');
    $liqueur = nameSearchNode($liquor, 'リキュール', 'Liqueur');
    $fruit = nameSearchNode($liqueur, 'フルーツ系', 'Fruit');
    $melon = nameSearchNode($fruit, 'メロンリキュール', 'Melonliqueur');
    $green = nameSearchNode($melon, '緑のメロンリキュール', 'Green Melon');
    $peach = nameSearchNode($fruit, 'ピーチリキュール', 'Peach Liqueur');
    $soft = nameSearchNode(null, 'ソフトドリンク', 'Soft Drink');
    $lemon = nameSearchNode($soft, 'レモン', 'Lemon');

    return compact('liquor', 'liqueur', 'fruit', 'melon', 'green', 'peach', 'soft', 'lemon');
}

it('TC-173-01: 語に「リキュール」を付けた名前のノードに当たり、そのノードを材料に使うカクテルを返す', function () {
    $tree = ingredientSearchTree();
    $uses = nameSearchUses(nameSearchCocktail('ミドリ・サワー', 'Midori Sour'), $tree['melon']);
    nameSearchUses(nameSearchCocktail('ピーチ・ウーロン', 'Peach Oolong'), $tree['peach']);

    assertIngredientMatches(nameSearch($this, 'メロン'), [$uses]);
    // 名前そのもの(語にリキュールを付けない)でも当たる
    assertIngredientMatches(nameSearch($this, 'メロンリキュール'), [$uses]);
    // 名前の一部だけ(部分一致)では当たらない(旧と同じく完全一致)
    assertIngredientMatches(nameSearch($this, 'メロ'), []);
});

it('TC-173-02: 当たったノードの配下(深いノード)と、根を除く祖先を材料に使うカクテルも返す', function () {
    $tree = ingredientSearchTree();
    $usesGreen = nameSearchUses(nameSearchCocktail('グリーン・スプラッシュ', 'Green Splash'), $tree['green']);
    $usesFruit = nameSearchUses(nameSearchCocktail('フルーツ・パンチ', 'Fruit Punch'), $tree['fruit']);
    $usesLiqueur = nameSearchUses(nameSearchCocktail('リキュール・トニック', 'Liqueur Tonic'), $tree['liqueur']);
    nameSearchUses(nameSearchCocktail('ピーチ・ウーロン', 'Peach Oolong'), $tree['peach']);

    // 配下の緑のメロンリキュールと、祖先のフルーツ系・リキュール(根の洋酒は除く)。兄弟のピーチリキュールは返さない
    assertIngredientMatches(nameSearch($this, 'メロン'), [$usesGreen, $usesFruit, $usesLiqueur]);
    // 4 段目のノードの名前でも探せる。配下は無く、祖先はメロンリキュール・フルーツ系・リキュール
    assertIngredientMatches(nameSearch($this, '緑のメロン'), [$usesGreen, $usesFruit, $usesLiqueur]);
});

it('TC-173-03: 銘柄の名前に当たったら、その銘柄を指定した材料を持つカクテルだけを返す', function () {
    $tree = ingredientSearchTree();
    $midori = nameSearchBrand('ミドリ', 'Midori', ['category_id' => $tree['melon']->id]);
    $other = nameSearchBrand('ボルス・メロン', 'Bols Melon', ['category_id' => $tree['melon']->id]);
    $usesMidori = nameSearchUses(nameSearchCocktail('ミドリ・サワー', 'Midori Sour'), $midori);
    nameSearchUses(nameSearchCocktail('メロン・ボール', 'Melon Ball'), $other);
    nameSearchUses(nameSearchCocktail('メロン・ソーダ', 'Melon Soda'), $tree['melon']);

    // 同じノードでも、別の銘柄や銘柄を指定していない材料は当たらない(旧と同じ)
    assertIngredientMatches(nameSearch($this, 'ミドリ'), [$usesMidori]);
});

it('TC-173-04: 空白で分けた 2 語は、両方の語の材料を使うカクテルだけを返す(AND)', function () {
    $tree = ingredientSearchTree();
    $both = nameSearchUses(nameSearchUses(nameSearchCocktail('メロン・サワー', 'Melon Sour'), $tree['melon']), $tree['lemon']);
    nameSearchUses(nameSearchCocktail('メロン・ソーダ', 'Melon Soda'), $tree['melon']);
    nameSearchUses(nameSearchCocktail('レモネード', 'Lemonade'), $tree['lemon']);

    // 半角・全角の空白のどちらでも分ける
    assertIngredientMatches(nameSearch($this, 'メロン レモン'), [$both]);
    assertIngredientMatches(nameSearch($this, 'メロン　レモン'), [$both]);
});

it('TC-173-05: どれかの語でノードにも銘柄にも当たらなければ、0 件', function () {
    $tree = ingredientSearchTree();
    nameSearchUses(nameSearchUses(nameSearchCocktail('メロン・サワー', 'Melon Sour'), $tree['melon']), $tree['lemon']);

    assertIngredientMatches(nameSearch($this, 'メロン ドラゴンフルーツ'), []);
    assertIngredientMatches(nameSearch($this, 'ドラゴンフルーツ'), []);
});

it('TC-173-06: 1 つの語でノードと銘柄の両方に当たったら、どちらかを使うカクテルを返す', function () {
    $tree = ingredientSearchTree();
    $cassisNode = nameSearchNode($tree['fruit'], 'カシス', 'Cassis');
    $cassisBrand = nameSearchBrand('カシスリキュール', 'Cassis Brand', ['category_id' => $tree['peach']->id]);
    $usesNode = nameSearchUses(nameSearchCocktail('カシス・ソーダ', 'Cassis Soda'), $cassisNode);
    $usesBrand = nameSearchUses(nameSearchCocktail('カシス・ウーロン', 'Cassis Oolong'), $cassisBrand);
    nameSearchUses(nameSearchCocktail('ピーチ・ウーロン', 'Peach Oolong'), $tree['peach']);

    // 語そのもの(カシス)でノードに、語＋リキュール(カシスリキュール)で銘柄に当たる
    assertIngredientMatches(nameSearch($this, 'カシス'), [$usesNode, $usesBrand]);
});

it('TC-173-07: 英数字だけの入力は、英名だけで完全一致を探し(大文字小文字は問わない)、付けるのは空白なしの liqueur', function () {
    $tree = ingredientSearchTree();
    $midori = nameSearchBrand('ミドリ', 'Midori', ['category_id' => $tree['melon']->id]);
    $usesMelon = nameSearchUses(nameSearchCocktail('メロン・ソーダ', 'Melon Soda'), $tree['melon']);
    $usesMidori = nameSearchUses(nameSearchCocktail('ミドリ・サワー', 'Midori Sour'), $midori);
    nameSearchUses(nameSearchCocktail('ピーチ・ウーロン', 'Peach Oolong'), $tree['peach']);

    // メロンリキュールの英名は Melonliqueur。melon に liqueur を付けて当たる。銘柄ミドリを指定した材料も、
    // 材料のノードはメロンリキュールなので、ミドリ・サワーも返る
    assertIngredientMatches(nameSearch($this, 'melon'), [$usesMelon, $usesMidori]);
    assertIngredientMatches(nameSearch($this, 'MIDORI'), [$usesMidori]);
    // ピーチリキュールの英名は Peach Liqueur(空白あり)。peach に liqueur を空白なしで付けるので当たらない(旧と同じ)
    assertIngredientMatches(nameSearch($this, 'peach'), []);
    // 英数字だけの入力は検索用の名前を見ない。ノード「ABC」の英名が別なら当たらない
    $abc = nameSearchNode($tree['fruit'], 'ABC', 'Another');
    nameSearchUses(nameSearchCocktail('エー・ビー・シー', 'A.B.C.'), $abc);
    assertIngredientMatches(nameSearch($this, 'ABC'), []);
});

it('TC-173-08: 下書きのカクテルは返さず、根(旧の種別)の名前に当たっても材料の検索には使わない', function () {
    $tree = ingredientSearchTree();
    $published = nameSearchUses(nameSearchCocktail('メロン・ソーダ', 'Melon Soda'), $tree['melon']);
    nameSearchUses(nameSearchCocktail('メロン・ボール', 'Melon Ball', ['is_draft' => true]), $tree['melon']);

    assertIngredientMatches(nameSearch($this, 'メロン'), [$published]);
    // 「洋酒」は根の名前にだけ当たる。根は探さないので、見つからない語と同じく 0 件
    assertIngredientMatches(nameSearch($this, '洋酒'), []);
});

it('TC-173-09: 任意の材料も対象にし、ID の順で、一覧と同じ項目を返す', function () {
    $tree = ingredientSearchTree();
    $second = nameSearchUses(nameSearchCocktail('メロン・ボール', 'Melon Ball'), $tree['melon'], optional: true);
    $first = nameSearchUses(nameSearchCocktail('メロン・ソーダ', 'Melon Soda', ['base_category_id' => $tree['melon']->id]), $tree['melon']);
    expect($first->id)->toBeGreaterThan($second->id);

    nameSearch($this, 'メロン')->assertOk()
        ->assertJsonPath('ingredient_matches.*.id', [$second->id, $first->id])
        ->assertJsonPath('ingredient_matches.1.name', 'メロン・ソーダ')
        ->assertJsonPath('ingredient_matches.1.base', ['id' => $tree['melon']->id, 'name' => 'メロンリキュール', 'name_eng' => 'Melonliqueur'])
        ->assertJsonStructure(['ingredient_matches' => [[
            'id', 'name', 'name_eng', 'is_major', 'base', 'method', 'recipe_count', 'strength', 'sweet_degree', 'rate', 'youtube', 'updated_at',
        ]]]);
});

/**
 * $cocktail に別名を足す。name_search は名前を NameNormalizer でそろえた値。
 */
function nameSearchAlias(Cocktail $cocktail, string $name): CocktailAlias
{
    return CocktailAlias::factory()->named($name)->create(['cocktail_id' => $cocktail->id]);
}

it('TC-82-13: 別名で探すと、そのカクテルを返し、当たった別名を matched_alias に返す', function () {
    $reggae = nameSearchCocktail('レゲエ・パンチ', 'Reggae Punch');
    nameSearchCocktail('ファジー・ネーブル', 'Fuzzy Navel');
    nameSearchAlias($reggae, 'ピーチ・ウーロン');

    nameSearch($this, 'ウーロン')->assertOk()
        ->assertJsonPath('cocktails.*.id', [$reggae->id])
        ->assertJsonPath('cocktails.0.name', 'レゲエ・パンチ')
        ->assertJsonPath('cocktails.0.matched_alias', 'ピーチ・ウーロン');
});

it('TC-82-14: 名前でも別名でも当たるカクテルは 1 件だけ返し、matched_alias は null', function () {
    $cocktail = nameSearchCocktail('ピーチ・ウーロン', 'Peach Oolong');
    nameSearchAlias($cocktail, 'ピーチ・ウーロン・ティー');

    nameSearch($this, 'ピーチ')->assertOk()
        ->assertJsonPath('cocktails.*.id', [$cocktail->id])
        ->assertJsonPath('cocktails.0.matched_alias', null);
});

it('TC-82-15: 名前で当たったカクテルと別名で当たったカクテルを、まとめて ID の順で返す', function () {
    $byAlias = nameSearchCocktail('レゲエ・パンチ', 'Reggae Punch');
    $byName = nameSearchCocktail('ピーチ・ツリー・フィズ', 'Peach Tree Fizz');
    nameSearchAlias($byAlias, 'ピーチ・ウーロン');
    expect($byName->id)->toBeGreaterThan($byAlias->id);

    nameSearch($this, 'ピーチ')->assertOk()
        ->assertJsonPath('cocktails.*.id', [$byAlias->id, $byName->id])
        ->assertJsonPath('cocktails.*.matched_alias', ['ピーチ・ウーロン', null]);
});

it('TC-82-16: 1 つのカクテルの別名が 2 つ当たったら、先に登録した別名を返す', function () {
    $cocktail = nameSearchCocktail('レゲエ・パンチ', 'Reggae Punch');
    nameSearchAlias($cocktail, 'ピーチ・ウーロン・ハイ');
    nameSearchAlias($cocktail, 'ピーチ・ウーロン');

    nameSearch($this, 'ウーロン')->assertOk()
        ->assertJsonPath('cocktails.*.id', [$cocktail->id])
        ->assertJsonPath('cocktails.0.matched_alias', 'ピーチ・ウーロン・ハイ');
});

it('TC-82-17: 別名も検索用の名前で探す(中黒・空白の有無、全角の英数字を問わない)。英数字だけの語でも探す', function (string $q) {
    $cocktail = nameSearchCocktail('レゲエ・パンチ', 'Reggae Punch');
    nameSearchAlias($cocktail, 'ピーチ・ウーロン 2');

    nameSearch($this, $q)->assertOk()
        ->assertJsonPath('cocktails.*.id', [$cocktail->id])
        ->assertJsonPath('cocktails.0.matched_alias', 'ピーチ・ウーロン 2');
})->with([
    '中黒なし' => ['ピーチウーロン'],
    '全角の数字' => ['ウーロン２'],
    '英数字だけ' => ['2'],
]);

it('TC-82-18: 下書きのカクテルは、別名で当たっても返さない', function () {
    $draft = nameSearchCocktail('レゲエ・パンチ', 'Reggae Punch', ['is_draft' => true]);
    nameSearchAlias($draft, 'ピーチ・ウーロン');

    nameSearch($this, 'ウーロン')->assertOk()->assertJsonPath('cocktails', []);
});

it('TC-82-19: 別名で当たったカクテルも、一覧に出す項目を返す。材料の検索(ingredient_matches)は別名を見ない', function () {
    $cocktail = nameSearchCocktail('レゲエ・パンチ', 'Reggae Punch');
    nameSearchAlias($cocktail, 'ピーチ・ウーロン');

    nameSearch($this, 'ウーロン')->assertOk()
        ->assertJsonStructure(['cocktails' => [[
            'id', 'name', 'name_eng', 'is_major', 'base', 'method', 'recipe_count', 'strength', 'sweet_degree', 'rate', 'youtube', 'updated_at',
            'matched_alias',
        ]]])
        ->assertJsonPath('ingredient_matches', []);
});
