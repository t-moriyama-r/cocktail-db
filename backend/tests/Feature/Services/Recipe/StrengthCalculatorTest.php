<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use App\Services\Recipe\StrengthCalculator;
use App\Services\Recipe\StrengthNotCalculableException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// StrengthCalculator(#160)が、旧の RecipeCalc::calcStrength と同じ度数を出すか、#158 で決めた案 X(有名な銘柄の無い
// ノードは祖先にあっても 0%)のとおりかを、MariaDB の木で確かめる。期待値は、旧の calcStrength をソースのまま取り出し、
// 同じ入力(旧の 2 段のカテゴリ・ミドルに読み替えたもの)を渡して出た値(PR 本文の表の「旧での結果」)。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木と銘柄。() の中は有名な銘柄。
 *
 * 洋酒(根)
 * ├ ジン(1。ジン A 40%)── 銘柄: ジン A 40%・ジン B 47%
 * ├ ラム(1。ラム A 40%)── 銘柄: ラム A 40%
 * │  └ ホワイトラム(2。なし)
 * │     └ キューバ産(3。なし)
 * └ リキュール(1。リキュール A 13%)── 銘柄: リキュール A 13%
 * ソフトドリンク(根。soft_drink)
 * └ ソーダ(1。なし)
 * その他(根。other)
 * └ ライム(1。なし)
 *
 * @return array{nodes: array<string, Category>, brands: array<string, Brand>}
 */
function strengthCalcTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['キューバ産'] = $add($n['ホワイトラム'], 'キューバ産');
    $n['リキュール'] = $add($n['洋酒'], 'リキュール');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['ライム'] = $add($n['その他'], 'ライム');

    $brand = fn (Category $node, string $name, float $volume): Brand => Brand::factory()
        ->create(['category_id' => $node->id, 'name' => $name, 'volume' => $volume]);
    $b = [];
    $b['ジン A'] = $brand($n['ジン'], 'ジン A', 40.0);
    $b['ジン B'] = $brand($n['ジン'], 'ジン B', 47.0);
    $b['ラム A'] = $brand($n['ラム'], 'ラム A', 40.0);
    $b['リキュール A'] = $brand($n['リキュール'], 'リキュール A', 13.0);
    $n['ジン']->update(['famous_brand_id' => $b['ジン A']->id]);
    $n['ラム']->update(['famous_brand_id' => $b['ラム A']->id]);
    $n['リキュール']->update(['famous_brand_id' => $b['リキュール A']->id]);

    return ['nodes' => $n, 'brands' => $b];
}

/**
 * 材料を並べてカクテルを作る。材料は [ノードの名前か銘柄, 量, 任意か]。
 *
 * @param  array{nodes: array<string, Category>, brands: array<string, Brand>}  $tree
 * @param  list<array{string|Brand, string, bool}>  $ingredients
 */
function strengthCalcCocktail(array $tree, array $ingredients): Cocktail
{
    $cocktail = Cocktail::factory()->create();
    foreach ($ingredients as $position => [$item, $amount, $isOptional]) {
        $factory = CocktailIngredient::factory();
        $factory = $item instanceof Brand
            ? $factory->forBrand($item)
            : $factory->state(['category_id' => $tree['nodes'][$item]->id]);
        $factory->create([
            'cocktail_id' => $cocktail->id,
            'position' => $position + 1,
            'amount' => $amount,
            'is_optional' => $isOptional,
        ]);
    }

    return $cocktail;
}

/**
 * 材料を並べたカクテルの度数を計算する。
 *
 * @param  list<array{string|Brand, string, bool}>  $ingredients
 */
function strengthCalc(array $ingredients): float
{
    return app(StrengthCalculator::class)->forCocktail(strengthCalcCocktail(strengthCalcTree(), $ingredients));
}

it('TC-160-01: 銘柄を決めた材料は、ノードの有名な銘柄ではなく、その銘柄の度数で計算する', function () {
    $tree = strengthCalcTree();
    $cocktail = strengthCalcCocktail($tree, [[$tree['brands']['ジン B'], '45ml', false], ['ソーダ', '15ml', false]]);

    // 47 × 45 / 60 = 35.25 → 35.3(有名な銘柄のジン A なら 30.0)
    expect(app(StrengthCalculator::class)->forCocktail($cocktail))->toBe(35.3);
});

it('TC-160-02: 銘柄を決めない材料は、材料のノードの有名な銘柄の度数で計算する', function () {
    // 40 × 30 / 120
    expect(strengthCalc([['ジン', '30ml', false], ['ソーダ', '90ml', false]]))->toBe(10.0);
});

it('TC-160-03: 有名な銘柄の無いミドル(2 段目)の材料は、親に有名な銘柄があっても 0% で計算する', function () {
    // ホワイトラム 0% × 30 + ジン 40% × 30 = 1200 / 60(親のラム A を使うなら 40.0)
    expect(strengthCalc([['ホワイトラム', '30ml', false], ['ジン', '30ml', false]]))->toBe(20.0);
});

it('TC-160-04: 有名な銘柄の無い 3 段目のノードの材料は、祖先に有名な銘柄があっても 0% で計算する', function () {
    // キューバ産 0% × 30 + ジン 40% × 30 = 1200 / 60(祖先のラム A を使うなら 40.0)
    expect(strengthCalc([['キューバ産', '30ml', false], ['ジン', '30ml', false]]))->toBe(20.0);
});

it('TC-160-05: 適量の材料だけなら、適量の材料の度数の平均を丸めずに返す', function () {
    // (40 + 13) / 2
    expect(strengthCalc([['ジン', '適量', false], ['リキュール', '適量', false]]))->toBe(26.5);
    // (40 + 0 + 0) / 3。旧も丸めずに返していた
    expect(strengthCalc([['ジン', '-', false], ['ソーダ', '適量', false], ['ライム', '適量', false]]))
        ->toBe(40 / 3);
});

it('TC-160-06: 適量の材料は、ほかの材料の 2 倍の量で、適量の材料の度数の平均として足す', function () {
    // (40 × 30 + 0 × 30 × 2) / 90 = 13.33… → 13.3
    expect(strengthCalc([['ジン', '30ml', false], ['ソーダ', '適量', false]]))->toBe(13.3);
});

it('TC-160-07: 度数 0 の材料だけなら 0% になる', function () {
    expect(strengthCalc([['ソーダ', '90ml', false], ['ライム', '30ml', false]]))->toBe(0.0);
});

it('TC-160-08: 量のある材料も適量の材料も無ければ、計算できない例外にする', function () {
    // tsp・dash だけ
    expect(fn () => strengthCalc([['ジン', '1tsp', false], ['ソーダ', '1dash', false]]))
        ->toThrow(StrengthNotCalculableException::class);
    // 材料が無い
    expect(fn () => strengthCalc([]))->toThrow(StrengthNotCalculableException::class);
});

it('TC-160-09: 任意の材料と「その他」の材料も、度数の計算に入れる', function () {
    // ジン(任意)40% × 30 + ライム 0% × 30 + ソーダ 0% × 30 = 1200 / 90 = 13.33… → 13.3
    expect(strengthCalc([['ジン', '30ml', true], ['ライム', '30ml', false], ['ソーダ', '30ml', false]]))->toBe(13.3);
});

it('TC-160-10: 度数は小数 1 桁に丸める', function () {
    // 40 × 45 / 65 = 27.69… → 27.7
    expect(strengthCalc([['ジン', '45ml', false], ['ソーダ', '20ml', false]]))->toBe(27.7);
});

it('TC-160-11: 量は AmountFormatter で ml に直す(分数は 100ml に対する割合、tsp は 0)', function () {
    // ジン 1/2 = 50ml、ソーダ 50ml、ジン 2tsp = 0ml → 40 × 50 / 100
    expect(strengthCalc([['ジン', '1/2', false], ['ソーダ', '50ml', false], ['ジン', '2tsp', false]]))->toBe(20.0);
});

it('TC-160-12: 保存する前の材料の行からも計算できる', function () {
    $tree = strengthCalcTree();
    $row = function (Category $node, ?Brand $brand, string $amount): CocktailIngredient {
        $ingredient = new CocktailIngredient(['amount' => $amount]);
        $ingredient->setRelation('category', $node->load('famousBrand'));
        $ingredient->setRelation('brand', $brand);

        return $ingredient;
    };

    $strength = app(StrengthCalculator::class)->calculate([
        $row($tree['nodes']['ジン'], $tree['brands']['ジン B'], '45ml'),
        $row($tree['nodes']['ソーダ'], null, '15ml'),
    ]);

    expect($strength)->toBe(35.3)
        ->and(CocktailIngredient::query()->count())->toBe(0);
});
