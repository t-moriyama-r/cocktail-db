<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// RecipeCounter(#156)が、docs/decisions/recipe-count.md の定義(任意でなく、「その他」の根の部分木に入っていない
// 材料の行の数)どおりに数え、部分木の移動で数え直すかを、MariaDB の閉包表で確かめる。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木。
 *
 * 洋酒(根)
 * ├ ラム(1)
 * │  └ ホワイトラム(2)── 銘柄: バカルディ
 * └ ジン(1)
 * ソフトドリンク(根。soft_drink)
 * └ ソーダ(1)
 * その他(根。other)
 * ├ 砂糖(1)
 * └ 果物(1)
 *    └ ライム(2)
 *
 * @return array<string, Category>
 */
function recipeCounterTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['砂糖'] = $add($n['その他'], '砂糖');
    $n['果物'] = $add($n['その他'], '果物');
    $n['ライム'] = $add($n['果物'], 'ライム');

    return $n;
}

/**
 * 材料を並べてカクテルを作る。材料は [ノードの名前, 任意か] か、銘柄つきなら [銘柄, 任意か]。
 *
 * @param  array<string, Category>  $nodes
 * @param  list<array{string|Brand, bool}>  $ingredients
 */
function recipeCounterCocktail(array $nodes, array $ingredients): Cocktail
{
    $cocktail = Cocktail::factory()->create();
    foreach ($ingredients as $position => [$item, $isOptional]) {
        $factory = CocktailIngredient::factory();
        $factory = $item instanceof Brand
            ? $factory->forBrand($item)
            : $factory->state(['category_id' => $nodes[$item]->id]);
        $factory->create([
            'cocktail_id' => $cocktail->id,
            'position' => $position + 1,
            'is_optional' => $isOptional,
        ]);
    }

    return $cocktail;
}

/**
 * 列 recipe_count に、数え直していないことが分かる値を直接入れる(RecipeCounter を通さずに書けるのはテストだけ)。
 */
function recipeCounterSetStale(Cocktail ...$cocktails): void
{
    DB::table('cocktails')
        ->whereIn('id', array_map(fn (Cocktail $cocktail): int => $cocktail->id, $cocktails))
        ->update(['recipe_count' => 99]);
}

/**
 * 列 recipe_count の今の値。
 */
function recipeCounterStored(Cocktail $cocktail): int
{
    return Cocktail::query()->findOrFail($cocktail->id)->recipe_count;
}

dataset('recipe count decision table', [
    '任意でない・その他でない(洋酒の部分木)' => ['ラム', false, 1],
    '任意・その他でない' => ['ラム', true, 0],
    '任意でない・その他(根の直下)' => ['砂糖', false, 0],
    '任意・その他' => ['砂糖', true, 0],
    '任意でない・その他(根から深さ 2)' => ['ライム', false, 0],
    '任意でない・ソフトドリンク' => ['ソーダ', false, 1],
]);

it('TC-156-01: 材料 1 行のカクテルを、任意か・「その他」か・どの根の部分木かの組み合わせごとに定義どおりに数える', function (string $node, bool $isOptional, int $expected) {
    $nodes = recipeCounterTree();
    $cocktail = recipeCounterCocktail($nodes, [[$node, $isOptional]]);

    expect(app(RecipeCounter::class)->count($cocktail))->toBe($expected);
})->with('recipe count decision table');

it('TC-156-02: 銘柄つきの材料は、材料のノード(銘柄の所属ノード)で数える', function () {
    $nodes = recipeCounterTree();
    $bacardi = Brand::factory()->create(['name' => 'バカルディ', 'category_id' => $nodes['ホワイトラム']->id]);
    $cocktail = recipeCounterCocktail($nodes, [[$bacardi, false]]);

    expect(app(RecipeCounter::class)->count($cocktail))->toBe(1);
});

it('TC-156-03: 同じ材料が 2 行あるカクテルは、行の数(2)と数える', function () {
    $nodes = recipeCounterTree();
    $cocktail = recipeCounterCocktail($nodes, [['ジン', false], ['ジン', false]]);

    expect(app(RecipeCounter::class)->count($cocktail))->toBe(2);
});

it('TC-156-04: 任意・その他・ソフトドリンクの混ざったカクテルは、任意でなく「その他」でない行だけを数える', function () {
    $nodes = recipeCounterTree();
    $cocktail = recipeCounterCocktail($nodes, [
        ['ラム', false], ['ジン', false], ['ソーダ', false], ['ライム', false], ['砂糖', true], ['ジン', true],
    ]);

    expect(app(RecipeCounter::class)->count($cocktail))->toBe(3);
});

it('TC-156-05: 材料の無いカクテルは 0 と数える', function () {
    recipeCounterTree();
    $cocktail = Cocktail::factory()->create();

    expect(app(RecipeCounter::class)->count($cocktail))->toBe(0);
});

it('TC-156-06: recount は渡したカクテルの recipe_count を数えた値で書き、渡していないカクテルには触れない', function () {
    $nodes = recipeCounterTree();
    $counted = recipeCounterCocktail($nodes, [['ラム', false], ['ソーダ', false], ['ライム', false]]);
    $alsoCounted = recipeCounterCocktail($nodes, [['ジン', false]]);
    $untouched = recipeCounterCocktail($nodes, [['ジン', false]]);
    recipeCounterSetStale($counted, $alsoCounted, $untouched);

    app(RecipeCounter::class)->recount([$counted->id, $alsoCounted->id]);

    expect(recipeCounterStored($counted))->toBe(2)
        ->and(recipeCounterStored($alsoCounted))->toBe(1)
        ->and(recipeCounterStored($untouched))->toBe(99);
});

it('TC-156-07: recount は、数える材料の無いカクテルを 0 にする', function () {
    $nodes = recipeCounterTree();
    $noIngredients = Cocktail::factory()->create();
    $onlyOther = recipeCounterCocktail($nodes, [['砂糖', false], ['ライム', false]]);
    recipeCounterSetStale($noIngredients, $onlyOther);

    app(RecipeCounter::class)->recount([$noIngredients->id, $onlyOther->id]);

    expect(recipeCounterStored($noIngredients))->toBe(0)
        ->and(recipeCounterStored($onlyOther))->toBe(0);
});

it('TC-156-08: recount に空の配列・同じ ID の重複を渡しても、エラーにならず定義どおりの値になる', function () {
    $nodes = recipeCounterTree();
    $cocktail = recipeCounterCocktail($nodes, [['ラム', false]]);
    recipeCounterSetStale($cocktail);

    app(RecipeCounter::class)->recount([]);
    expect(recipeCounterStored($cocktail))->toBe(99);

    app(RecipeCounter::class)->recount([$cocktail->id, $cocktail->id]);
    expect(recipeCounterStored($cocktail))->toBe(1);
});

it('TC-156-09: recount はカクテルの updated_at を変えない', function () {
    $nodes = recipeCounterTree();
    $cocktail = recipeCounterCocktail($nodes, [['ラム', false]]);
    DB::table('cocktails')->where('id', $cocktail->id)->update(['updated_at' => '2019-01-02 03:04:05']);

    app(RecipeCounter::class)->recount([$cocktail->id]);

    expect(DB::table('cocktails')->where('id', $cocktail->id)->value('updated_at'))->toBe('2019-01-02 03:04:05');
});

it('TC-156-10: 部分木を「その他」の根の下へ移すと、部分木のノードを使うカクテルの数が減り、関係のないカクテルは数え直さない', function () {
    $nodes = recipeCounterTree();
    $usesRum = recipeCounterCocktail($nodes, [['ホワイトラム', false], ['ジン', false]]);
    $unrelated = recipeCounterCocktail($nodes, [['ジン', false], ['ソーダ', false]]);
    app(RecipeCounter::class)->recount([$usesRum->id]);
    recipeCounterSetStale($unrelated);

    app(CategoryTree::class)->moveSubtree($nodes['ラム'], $nodes['その他']);

    expect(recipeCounterStored($usesRum))->toBe(1)
        ->and(recipeCounterStored($unrelated))->toBe(99);
});

it('TC-156-11: 部分木を「その他」の根から出すと、部分木のノードを使うカクテルの数が増える', function () {
    $nodes = recipeCounterTree();
    $usesLime = recipeCounterCocktail($nodes, [['ライム', false], ['ジン', false]]);
    $usesSugar = recipeCounterCocktail($nodes, [['砂糖', false], ['ジン', false]]);
    app(RecipeCounter::class)->recount([$usesLime->id, $usesSugar->id]);

    app(CategoryTree::class)->moveSubtree($nodes['果物'], $nodes['洋酒']);

    expect(recipeCounterStored($usesLime))->toBe(2)
        ->and(recipeCounterStored($usesSugar))->toBe(1);
});

it('TC-156-12: 材料のノードが部分木の外でも、部分木にぶら下がる銘柄を材料に使うカクテルは、移したときに数え直す', function () {
    $nodes = recipeCounterTree();
    $bacardi = Brand::factory()->create(['name' => 'バカルディ', 'category_id' => $nodes['ホワイトラム']->id]);
    // 移行で残す「材料のノードは親(ラム)、銘柄はその子(ホワイトラム)の下」の行(docs/migration-anomalies.md の 11)
    $usesBacardi = recipeCounterCocktail($nodes, [['ジン', false]]);
    CocktailIngredient::factory()->create([
        'cocktail_id' => $usesBacardi->id,
        'position' => 2,
        'category_id' => $nodes['ラム']->id,
        'brand_id' => $bacardi->id,
        'is_optional' => false,
    ]);
    recipeCounterSetStale($usesBacardi);

    app(CategoryTree::class)->moveSubtree($nodes['ホワイトラム'], $nodes['果物']);

    // 数えるかは材料のノード(ラム。移していない)で決まるので 2。数え直したことは、入れておいた 99 が消えたことで分かる
    expect(recipeCounterStored($usesBacardi))->toBe(2);
});

it('TC-156-13: 根の役割が変わる移動(役割の無い根 → ソフトドリンク)では、数は変わらないが数え直す', function () {
    $nodes = recipeCounterTree();
    $usesRum = recipeCounterCocktail($nodes, [['ラム', false], ['ジン', false]]);
    recipeCounterSetStale($usesRum);

    app(CategoryTree::class)->moveSubtree($nodes['ラム'], $nodes['ソフトドリンク']);

    expect(recipeCounterStored($usesRum))->toBe(2);
});

it('TC-156-14: 同じ根の中での移動で根の役割が変わらないときは、数え直さない', function () {
    $nodes = recipeCounterTree();
    $usesWhiteRum = recipeCounterCocktail($nodes, [['ホワイトラム', false]]);
    $usesLime = recipeCounterCocktail($nodes, [['ライム', false]]);
    recipeCounterSetStale($usesWhiteRum, $usesLime);

    app(CategoryTree::class)->moveSubtree($nodes['ホワイトラム'], $nodes['ジン']);
    app(CategoryTree::class)->moveSubtree($nodes['ライム'], $nodes['砂糖']);

    expect(recipeCounterStored($usesWhiteRum))->toBe(99)
        ->and(recipeCounterStored($usesLime))->toBe(99);
});

it('TC-156-15: 「その他」の部分木を根の位置へ移すと、役割の無い根になり、数え直して数が増える', function () {
    $nodes = recipeCounterTree();
    $usesLime = recipeCounterCocktail($nodes, [['ライム', false]]);
    app(RecipeCounter::class)->recount([$usesLime->id]);

    app(CategoryTree::class)->moveSubtree($nodes['果物'], null);

    expect(recipeCounterStored($usesLime))->toBe(1);
});
