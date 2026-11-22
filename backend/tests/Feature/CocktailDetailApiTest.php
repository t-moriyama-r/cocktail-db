<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\Ice;
use App\Models\Method;
use App\Models\User;
use App\Services\AdminLoginLinks;
use App\Services\CategoryTree;
use App\Services\RecipeSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// カクテルの詳細の API(GET /api/cocktails/{id}・GET /api/admin/cocktails/{id}/preview。#40)。
// 期待値は、旧システム(手元で動かした旧の API)の応答と、docs/decisions/cocktail-detail.md の旧との差分から置く。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 旧のカルーア・フィズ(ID 83)と同じ形の材料を持つ木。
 *
 * 醸造酒(根)── 日本酒
 * リキュール(根)── コーヒー・リキュール ── 銘柄: カルーア
 * ソフトドリンク(根。soft_drink)── レモン・ジュース・ソーダ
 * その他(根。other)── 卵 ── 卵白
 *
 * @return array<string, Category>
 */
function cocktailDetailTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, string $nameEng, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'name_eng' => $nameEng, 'role' => $role])->getAttributes(),
    );

    $n = [];
    $n['醸造酒'] = $add(null, '醸造酒', 'Brewed');
    $n['日本酒'] = $add($n['醸造酒'], '日本酒', 'Sake');
    $n['リキュール'] = $add(null, 'リキュール', 'Liqueur');
    $n['コーヒー・リキュール'] = $add($n['リキュール'], 'コーヒー・リキュール', 'Coffee Liqueur');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'Soft Drink', 'soft_drink');
    $n['レモン・ジュース'] = $add($n['ソフトドリンク'], 'レモン・ジュース', 'Lemon Juice');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ', 'Soda');
    $n['その他'] = $add(null, 'その他', 'Other', 'other');
    $n['卵'] = $add($n['その他'], '卵', 'Egg');
    $n['卵白'] = $add($n['卵'], '卵白', 'Albumin');

    return $n;
}

/**
 * ノードを、応答のパンくずの 1 段の形にする。
 *
 * @return array{id: int, name: string, name_eng: string}
 */
function cocktailDetailNodeRef(Category $node): array
{
    return ['id' => $node->id, 'name' => $node->name, 'name_eng' => $node->name_eng];
}

/**
 * 技法を作る。用語を渡せば、その用語を技法に結び付ける。
 */
function cocktailDetailMethod(string $name, string $nameEng, ?Dictionary $dictionary = null): Method
{
    return Method::factory()->create(['name' => $name, 'name_eng' => $nameEng, 'dictionary_id' => $dictionary?->id]);
}

/**
 * ログインした管理者のセッションで、管理 API を呼べるようにする。
 */
function actingAsCocktailAdmin(TestCase $test): void
{
    $test->get(app(AdminLoginLinks::class)->issue(User::factory()->create()))->assertRedirect('/admin');
}

beforeEach(function () {
    $this->withoutVite();
});

it('TC-40-01: 基本情報・ベース・技法(用語の ID つき)・グラス・氷を返す', function () {
    $n = cocktailDetailTree();
    $shakeTerm = Dictionary::factory()->create(['name' => 'シェイク', 'name_eng' => 'Shake']);
    $method = cocktailDetailMethod('シェイク', 'Shake', $shakeTerm);
    $glass = Dictionary::factory()->create(['name' => 'カクテル・グラス', 'name_eng' => 'Cocktail glass']);
    $ice = Ice::query()->create(['name' => '氷入り', 'name_eng' => null]);
    $cocktail = Cocktail::factory()->create([
        'name' => 'カルーア・フィズ', 'name_eng' => 'Kahlua Fizz', 'base_category_id' => $n['日本酒']->id,
        'method_id' => $method->id, 'glass_dictionary_id' => $glass->id, 'ice_id' => $ice->id,
        'volume' => 8.7, 'sweet_degree' => -3, 'rate' => 2, 'is_major' => true,
        'description' => '名前はカルーアから。', 'note' => '補足',
        'instagram' => 'BTRQfTjlnAT', 'youtube' => 'abcdefghijk', 'youtube_start_seconds' => 61,
        'image' => 'kahlua.jpg', 'image_txt' => '出典', 'image_url' => 'https://example.com/',
    ]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $cocktail->id)
        ->assertJsonPath('data.name', 'カルーア・フィズ')
        ->assertJsonPath('data.name_eng', 'Kahlua Fizz')
        ->assertJsonPath('data.is_draft', false)
        ->assertJsonPath('data.is_major', true)
        ->assertJsonPath('data.base', cocktailDetailNodeRef($n['日本酒']))
        ->assertJsonPath('data.method', [
            'id' => $method->id, 'name' => 'シェイク', 'name_eng' => 'Shake', 'dictionary_id' => $shakeTerm->id,
        ])
        ->assertJsonPath('data.glass', ['id' => $glass->id, 'name' => 'カクテル・グラス', 'name_eng' => 'Cocktail glass'])
        ->assertJsonPath('data.ice', ['id' => $ice->id, 'name' => '氷入り', 'name_eng' => null])
        ->assertJsonPath('data.strength', 8.7)
        ->assertJsonPath('data.sweet_degree', -3)
        ->assertJsonPath('data.rate', 2)
        // 説明文・補足は区切りの並び(#286。リンクの区切りは TC-286-10・11)
        ->assertJsonPath('data.description', [['type' => 'text', 'text' => '名前はカルーアから。']])
        ->assertJsonPath('data.note', [['type' => 'text', 'text' => '補足']])
        ->assertJsonPath('data.instagram', 'BTRQfTjlnAT')
        ->assertJsonPath('data.youtube', 'abcdefghijk')
        ->assertJsonPath('data.youtube_start_seconds', 61)
        ->assertJsonPath('data.image', 'kahlua.jpg')
        ->assertJsonPath('data.image_txt', '出典')
        ->assertJsonPath('data.image_url', 'https://example.com/');
});

it('TC-40-02: 材料を並び順(position)の昇順で返す(入れた順によらない)', function () {
    $n = cocktailDetailTree();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id]);
    // 旧のカルーア・フィズと同じ並び(30ml・20ml・15ml・1tsp・50ml)を、ばらばらの順に入れる
    foreach ([[4, '卵白', '1tsp'], [1, '日本酒', '30ml'], [5, 'ソーダ', '50ml'], [3, 'レモン・ジュース', '15ml'], [2, 'コーヒー・リキュール', '20ml']] as [$position, $node, $amount]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id, 'position' => $position, 'category_id' => $n[$node]->id, 'amount' => $amount,
        ]);
    }

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.ingredients.*.position', [1, 2, 3, 4, 5])
        ->assertJsonPath('data.ingredients.*.amount', ['30ml', '20ml', '15ml', '1tsp', '50ml']);
});

it('TC-40-03: 材料ごとに、根から材料のノードまでのパンくずと銘柄を返す', function () {
    $n = cocktailDetailTree();
    $kahlua = Brand::factory()->create(['category_id' => $n['コーヒー・リキュール']->id, 'name' => 'カルーア', 'name_eng' => 'Kahlua']);
    $cocktail = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id]);
    CocktailIngredient::factory()->forBrand($kahlua)->create(['cocktail_id' => $cocktail->id, 'position' => 1, 'amount' => '20ml']);
    CocktailIngredient::factory()->create([
        'cocktail_id' => $cocktail->id, 'position' => 2, 'category_id' => $n['卵白']->id, 'amount' => '1tsp',
    ]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.ingredients', [
            [
                'position' => 1, 'amount' => '20ml', 'is_optional' => false,
                'path' => [cocktailDetailNodeRef($n['リキュール']), cocktailDetailNodeRef($n['コーヒー・リキュール'])],
                'brand' => ['id' => $kahlua->id, 'name' => 'カルーア', 'name_eng' => 'Kahlua'],
            ],
            [
                'position' => 2, 'amount' => '1tsp', 'is_optional' => false,
                'path' => [cocktailDetailNodeRef($n['その他']), cocktailDetailNodeRef($n['卵']), cocktailDetailNodeRef($n['卵白'])],
                'brand' => null,
            ],
        ]);
});

it('TC-40-04: 任意の材料は is_optional が true', function () {
    $n = cocktailDetailTree();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id]);
    CocktailIngredient::factory()->create([
        'cocktail_id' => $cocktail->id, 'position' => 1, 'category_id' => $n['ソーダ']->id, 'amount' => '適量', 'is_optional' => true,
    ]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.ingredients.0.is_optional', true);
});

it('TC-40-05: グラス・氷を決めていない・材料が無いカクテルは、glass・ice が null で ingredients が空', function () {
    $n = cocktailDetailTree();
    $cocktail = Cocktail::factory()->create([
        'base_category_id' => $n['日本酒']->id, 'glass_dictionary_id' => null, 'ice_id' => null,
        'sweet_degree' => null, 'rate' => null, 'description' => null, 'note' => null,
    ]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.glass', null)
        ->assertJsonPath('data.ice', null)
        ->assertJsonPath('data.sweet_degree', null)
        ->assertJsonPath('data.rate', null)
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.note', null)
        ->assertJsonPath('data.ingredients', []);
});

it('TC-40-06: 用語を結び付けていない技法は dictionary_id が null', function () {
    $n = cocktailDetailTree();
    $method = cocktailDetailMethod('ホット', 'Hot');
    $cocktail = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id, 'method_id' => $method->id]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.method.dictionary_id', null);
});

it('TC-40-07: 下書きのカクテルは 404', function () {
    $n = cocktailDetailTree();
    $draft = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id, 'is_draft' => true]);

    $this->getJson("/api/cocktails/{$draft->id}")->assertNotFound();
});

it('TC-40-08: 無い ID は 404', function () {
    $this->getJson('/api/cocktails/999999')->assertNotFound();
});

it('TC-40-23: int を超える桁の ID も 500 にならず 404', function () {
    $this->getJson('/api/cocktails/99999999999999999999')->assertNotFound();
    $this->getJson('/api/cocktails/99999999999999999999/same-name')->assertNotFound();
});

it('TC-40-09: ログインした管理者は、管理 API で下書きのカクテルの詳細を公開と同じ形で見られる', function () {
    $n = cocktailDetailTree();
    $draft = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id, 'is_draft' => true]);
    CocktailIngredient::factory()->create(['cocktail_id' => $draft->id, 'position' => 1, 'category_id' => $n['卵白']->id]);
    $published = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id]);
    actingAsCocktailAdmin($this);

    $this->getJson("/api/admin/cocktails/{$draft->id}/preview")
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id)
        ->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.ingredients.0.path.2', cocktailDetailNodeRef($n['卵白']));

    // 公開しているカクテルも、公開の API と同じ中身を返す
    expect($this->getJson("/api/admin/cocktails/{$published->id}/preview")->assertOk()->json())
        ->toBe($this->getJson("/api/cocktails/{$published->id}")->assertOk()->json());
});

it('TC-40-10: ログインしていないと、管理 API の詳細は 401', function () {
    $n = cocktailDetailTree();
    $draft = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id, 'is_draft' => true]);

    $this->getJson("/api/admin/cocktails/{$draft->id}/preview")->assertUnauthorized();
});

it('TC-40-11: ログインしていても、公開の API では下書きは 404(公開の API はセッションを見ない)', function () {
    $n = cocktailDetailTree();
    $draft = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id, 'is_draft' => true]);
    actingAsCocktailAdmin($this);

    $this->getJson("/api/cocktails/{$draft->id}")->assertNotFound();
});

it('TC-40-12: 公開の詳細の API は、セッションのクッキーを付けない', function () {
    $n = cocktailDetailTree();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $n['日本酒']->id]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertCookieMissing(config()->string('session.cookie'));
});

it('TC-286-10: 公開の詳細は、説明文と補足をリンクの区切りの並びで返す(下書き・無い参照は文字・改行はそのまま)', function () {
    $n = cocktailDetailTree();
    $brand = Brand::factory()->create(['category_id' => $n['コーヒー・リキュール']->id, 'name' => 'カルーア', 'youtube' => 'kahluavideo']);
    $draft = Cocktail::factory()->create(['name' => '下書きのカクテル', 'base_category_id' => $n['日本酒']->id, 'is_draft' => true]);
    $cocktail = Cocktail::factory()->create([
        'base_category_id' => $n['日本酒']->id,
        'description' => "@n{$n['日本酒']->id}@を使う。\n@b{$brand->id}@と@c{$draft->id}@と@c999999@",
        'note' => "補足\n2行目",
    ]);

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.description', [
            ['type' => 'node', 'id' => $n['日本酒']->id, 'text' => '日本酒', 'has_youtube' => false],
            ['type' => 'text', 'text' => "を使う。\n"],
            ['type' => 'brand', 'id' => $brand->id, 'text' => 'カルーア', 'has_youtube' => true],
            ['type' => 'text', 'text' => 'と下書きのカクテルと'],
        ])
        ->assertJsonPath('data.note', [['type' => 'text', 'text' => "補足\n2行目"]]);
});

it('TC-286-11: 管理者のプレビューも、下書きのカクテルの説明文をリンクの区切りの並びで返す', function () {
    $n = cocktailDetailTree();
    $draft = Cocktail::factory()->create([
        'base_category_id' => $n['日本酒']->id, 'is_draft' => true,
        'description' => "@n{$n['卵白']->id}@を泡立てる", 'note' => null,
    ]);
    actingAsCocktailAdmin($this);

    $this->getJson("/api/admin/cocktails/{$draft->id}/preview")
        ->assertOk()
        ->assertJsonPath('data.description', [
            ['type' => 'node', 'id' => $n['卵白']->id, 'text' => '卵白', 'has_youtube' => false],
            ['type' => 'text', 'text' => 'を泡立てる'],
        ])
        ->assertJsonPath('data.note', null);
});

/**
 * 材料を並べてカクテルを作り、recipe_signature を作り直す(材料を保存したときと同じ)。材料は [ノード, 量]。
 *
 * @param  list<array{Category, string}>  $rows
 * @param  array<string, mixed>  $attrs
 */
function cocktailDetailRecipe(array $rows, array $attrs = []): Cocktail
{
    $cocktail = Cocktail::factory()->create($attrs);
    foreach ($rows as $position => [$node, $amount]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id, 'position' => $position + 1, 'category_id' => $node->id, 'amount' => $amount,
        ]);
    }
    app(RecipeSignature::class)->rewrite([$cocktail->id]);

    return $cocktail->refresh();
}

it('TC-82-20: 別名を、登録した順に ID と名前で返す', function () {
    $cocktail = Cocktail::factory()->create(['name' => 'レゲエ・パンチ']);
    $first = CocktailAlias::factory()->named('ピーチ・ウーロン')->create(['cocktail_id' => $cocktail->id]);
    $second = CocktailAlias::factory()->named('ピーチ・ウーロン・ハイ')->create(['cocktail_id' => $cocktail->id]);
    CocktailAlias::factory()->named('ほかのカクテルの別名')->create();

    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.aliases', [
            ['id' => $first->id, 'name' => 'ピーチ・ウーロン'],
            ['id' => $second->id, 'name' => 'ピーチ・ウーロン・ハイ'],
        ]);
});

it('TC-82-21: 材料の組がまったく同じ、ほかの公開しているカクテルを ID の順で same_recipe に返す(自分・下書き・違うレシピは返さない)', function () {
    $n = cocktailDetailTree();
    $recipe = [[$n['コーヒー・リキュール'], '45ml'], [$n['ソーダ'], '適量']];
    $target = cocktailDetailRecipe($recipe, ['name' => 'コーラス・ガール']);
    // 並び順が違っても同じレシピ
    $same = cocktailDetailRecipe(array_reverse($recipe), ['name' => 'コーラス・レディ']);
    $sameLater = cocktailDetailRecipe($recipe, ['name' => 'カルーア・ソーダ']);
    cocktailDetailRecipe($recipe, ['name' => '下書き', 'is_draft' => true]);
    cocktailDetailRecipe([[$n['コーヒー・リキュール'], '30ml'], [$n['ソーダ'], '適量']], ['name' => '量が違う']);
    cocktailDetailRecipe([[$n['コーヒー・リキュール'], '45ml']], ['name' => '材料が足りない']);

    $this->getJson("/api/cocktails/{$target->id}")
        ->assertOk()
        ->assertJsonPath('data.same_recipe.*.id', [$same->id, $sameLater->id])
        ->assertJsonPath('data.same_recipe.0.name', 'コーラス・レディ')
        ->assertJsonStructure(['data' => ['same_recipe' => [[
            'id', 'name', 'name_eng', 'is_major', 'base', 'method', 'recipe_count', 'strength', 'sweet_degree', 'rate', 'youtube', 'updated_at',
        ]]]]);
});

it('TC-82-22: 材料を変えて recipe_signature を作り直すと、同じレシピの一覧が変わる', function () {
    $n = cocktailDetailTree();
    $recipe = [[$n['コーヒー・リキュール'], '45ml'], [$n['ソーダ'], '適量']];
    $a = cocktailDetailRecipe($recipe);
    $b = cocktailDetailRecipe($recipe);
    $c = cocktailDetailRecipe([[$n['コーヒー・リキュール'], '30ml'], [$n['ソーダ'], '適量']]);
    $this->getJson("/api/cocktails/{$a->id}")->assertJsonPath('data.same_recipe.*.id', [$b->id]);
    $before = $a->recipe_signature;

    // a の量を c と同じにする
    CocktailIngredient::query()->where('cocktail_id', $a->id)->where('position', 1)->update(['amount' => '30ml']);
    app(RecipeSignature::class)->rewrite([$a->id]);

    expect($a->refresh()->recipe_signature)->not->toBe($before)->toBe($c->refresh()->recipe_signature);
    $this->getJson("/api/cocktails/{$a->id}")->assertJsonPath('data.same_recipe.*.id', [$c->id]);
    $this->getJson("/api/cocktails/{$b->id}")->assertJsonPath('data.same_recipe', []);
});

it('TC-82-23: 別名が無く材料も無いカクテルは、aliases も same_recipe も空(材料の無いほかのカクテルとも同じにしない)', function () {
    $n = cocktailDetailTree();
    $cocktail = cocktailDetailRecipe([], ['base_category_id' => $n['日本酒']->id]);
    cocktailDetailRecipe([], ['base_category_id' => $n['日本酒']->id]);

    expect($cocktail->recipe_signature)->toBeNull();
    $this->getJson("/api/cocktails/{$cocktail->id}")
        ->assertOk()
        ->assertJsonPath('data.aliases', [])
        ->assertJsonPath('data.same_recipe', []);
});

it('TC-82-24: 管理者のプレビューでは、下書きのカクテルにも別名と同じレシピの公開しているカクテルを返す', function () {
    $n = cocktailDetailTree();
    $recipe = [[$n['コーヒー・リキュール'], '45ml']];
    $draft = cocktailDetailRecipe($recipe, ['is_draft' => true]);
    $published = cocktailDetailRecipe($recipe);
    $alias = CocktailAlias::factory()->named('別名')->create(['cocktail_id' => $draft->id]);
    actingAsCocktailAdmin($this);

    $this->getJson("/api/admin/cocktails/{$draft->id}/preview")
        ->assertOk()
        ->assertJsonPath('data.aliases', [['id' => $alias->id, 'name' => '別名']])
        ->assertJsonPath('data.same_recipe.*.id', [$published->id]);
});
