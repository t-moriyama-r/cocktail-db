<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\Method;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// data:verify(木の外の整合性)を、MariaDB で確かめる。正しいデータを組んでから、決まりを 1 つずつ壊して失敗することを見る。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。壊すのは材料・アフィリエイト・カクテル・
// ノードの列で、閉包表は壊さない(閉包表は tree:verify が見る)。

uses(RefreshDatabase::class)->group('db');

/**
 * 決まりどおりのデータ。
 *
 * 洋酒(根)
 * ├ ラム ── 有名な銘柄: バカルディ(子孫のホワイトラムの銘柄)
 * │  └ ホワイトラム ── 銘柄: バカルディ。有名な銘柄: バカルディ
 * └ ジン ── 銘柄: ビーフィーター。有名な銘柄: ビーフィーター
 * ソフトドリンク(根。soft_drink)
 * └ ソーダ ── 有名な銘柄なし(要らない)
 * その他(根。other)
 * └ ライム ── 有名な銘柄なし(要らない)
 *
 * カクテル: ラムソーダ(バカルディ・ソーダ・ライム(任意でない)。材料の数 2)、ライムだけ(ライム。材料の数 0)
 * アフィリエイト: ジンのノードの行、ビーフィーターの銘柄の行
 *
 * @return array{nodes: array<string, Category>, brands: array<string, Brand>, cocktails: array<string, Cocktail>}
 */
function verifyDataFixture(): array
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
    $n['ライム'] = $add($n['その他'], 'ライム');

    $b = [];
    $b['バカルディ'] = Brand::factory()->create(['category_id' => $n['ホワイトラム']->id, 'name' => 'バカルディ']);
    $b['ビーフィーター'] = Brand::factory()->create(['category_id' => $n['ジン']->id, 'name' => 'ビーフィーター']);
    $n['ラム']->update(['famous_brand_id' => $b['バカルディ']->id]);
    $n['ホワイトラム']->update(['famous_brand_id' => $b['バカルディ']->id]);
    $n['ジン']->update(['famous_brand_id' => $b['ビーフィーター']->id]);

    $c = [];
    $c['ラムソーダ'] = Cocktail::factory()->create(['name' => 'ラムソーダ', 'base_category_id' => $n['ラム']->id]);
    CocktailIngredient::factory()->forBrand($b['バカルディ'])->create(['cocktail_id' => $c['ラムソーダ']->id, 'position' => 0]);
    CocktailIngredient::factory()->create(['cocktail_id' => $c['ラムソーダ']->id, 'position' => 1, 'category_id' => $n['ソーダ']->id]);
    CocktailIngredient::factory()->create(['cocktail_id' => $c['ラムソーダ']->id, 'position' => 2, 'category_id' => $n['ライム']->id]);
    $c['ライムだけ'] = Cocktail::factory()->create(['name' => 'ライムだけ', 'base_category_id' => $n['ライム']->id]);
    CocktailIngredient::factory()->create(['cocktail_id' => $c['ライムだけ']->id, 'position' => 0, 'category_id' => $n['ライム']->id]);
    app(RecipeCounter::class)->recount([$c['ラムソーダ']->id, $c['ライムだけ']->id]);

    Affiliate::factory()->create(['category_id' => $n['ジン']->id]);
    Affiliate::factory()->create(['category_id' => $n['ジン']->id, 'brand_id' => $b['ビーフィーター']->id]);

    return ['nodes' => $n, 'brands' => $b, 'cocktails' => $c];
}

/**
 * data:verify を流し、終了コードと出力を返す。
 *
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function runVerifyData(array $options = []): array
{
    $code = Artisan::call('data:verify', $options);

    return [$code, Artisan::output()];
}

it('TC-157-01: 決まりどおりのデータでは、問題なしで 0 で終わる', function () {
    verifyDataFixture();

    [$code, $output] = runVerifyData();

    expect($code)->toBe(0)
        ->and($output)->toContain('問題なし')
        ->and($output)->not->toContain('警告');
});

it('TC-157-02: 銘柄を持つ材料のノードが銘柄の所属と違うと、その材料を挙げて失敗する', function () {
    $data = verifyDataFixture();
    CocktailIngredient::query()->where('cocktail_id', $data['cocktails']['ラムソーダ']->id)->where('position', 0)
        ->update(['category_id' => $data['nodes']['ラム']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('材料のノードと銘柄の所属')
        ->and($output)->toContain("カクテル #{$data['cocktails']['ラムソーダ']->id} ラムソーダ の 0 行目")
        ->and($output)->toContain("材料のノードは #{$data['nodes']['ラム']->id} ラム")
        ->and($output)->toContain("の所属は #{$data['nodes']['ホワイトラム']->id} ホワイトラム");
});

it('TC-157-03: 銘柄のアフィリエイトのノードが銘柄の所属と違うと、その行を挙げて失敗する', function () {
    $data = verifyDataFixture();
    $affiliate = Affiliate::query()->where('brand_id', $data['brands']['ビーフィーター']->id)->sole();
    $affiliate->update(['category_id' => $data['nodes']['ラム']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('アフィリエイトのノードと銘柄の所属')
        ->and($output)->toContain("アフィリエイト #{$affiliate->id}")
        ->and($output)->toContain("の所属は #{$data['nodes']['ジン']->id} ジン");
});

it('TC-157-04: recipe_count が数え直した値と違うと、そのカクテルを挙げて失敗する', function (string $name, int $stored, int $expected) {
    $data = verifyDataFixture();
    $cocktail = $data['cocktails'][$name];
    Cocktail::query()->whereKey($cocktail->id)->update(['recipe_count' => $stored]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('材料の数(recipe_count)')
        ->and($output)->toContain("カクテル #{$cocktail->id} {$name}")
        ->and($output)->toContain("いま {$stored}・数え直すと {$expected}");
})->with([
    '多い' => ['ラムソーダ', 3, 2],
    '少ない' => ['ラムソーダ', 1, 2],
    '数える材料が無いのに 0 でない' => ['ライムだけ', 1, 0],
]);

it('TC-157-05: 材料に根を使うと、その材料を挙げて失敗する', function () {
    $data = verifyDataFixture();
    CocktailIngredient::query()->where('cocktail_id', $data['cocktails']['ラムソーダ']->id)->where('position', 1)
        ->update(['category_id' => $data['nodes']['ソフトドリンク']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('材料に根')
        ->and($output)->toContain("材料のノード #{$data['nodes']['ソフトドリンク']->id} ソフトドリンク が根");
});

it('TC-157-06: ベースに根を使うと、そのカクテルを挙げて失敗する', function () {
    $data = verifyDataFixture();
    Cocktail::query()->whereKey($data['cocktails']['ラムソーダ']->id)->update(['base_category_id' => $data['nodes']['洋酒']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('ベースに根')
        ->and($output)->toContain("ベースのノード #{$data['nodes']['洋酒']->id} 洋酒 が根");
});

it('TC-157-07: 有名な銘柄がそのノードの部分木の外の銘柄だと、そのノードを挙げて失敗する', function () {
    $data = verifyDataFixture();
    $data['nodes']['ジン']->update(['famous_brand_id' => $data['brands']['バカルディ']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('有名な銘柄が部分木の外')
        ->and($output)->toContain("ノード #{$data['nodes']['ジン']->id} ジン")
        ->and($output)->toContain("の所属 #{$data['nodes']['ホワイトラム']->id} ホワイトラム が、このノードの部分木に無い");
});

it('TC-157-08: 有名な銘柄の要らない部分木のノードでも、部分木の外の銘柄を有名な銘柄にすると失敗する', function () {
    $data = verifyDataFixture();
    $data['nodes']['ソーダ']->update(['famous_brand_id' => $data['brands']['ビーフィーター']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain("ノード #{$data['nodes']['ソーダ']->id} ソーダ");
});

it('TC-157-09: 根の役割が null の部分木で有名な銘柄が無いノードは、警告に出すが 0 で終わる', function () {
    $data = verifyDataFixture();
    $data['nodes']['ジン']->update(['famous_brand_id' => null]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(0)
        ->and($output)->toContain('警告(1 件)')
        ->and($output)->toContain('有名な銘柄が無い')
        ->and($output)->toContain("ノード #{$data['nodes']['ジン']->id} ジン")
        ->and($output)->toContain('失敗に数える破れは無い');
});

it('TC-157-10: --strict のときは、有名な銘柄が無いノードの警告も失敗に数える', function () {
    $data = verifyDataFixture();
    $data['nodes']['ジン']->update(['famous_brand_id' => null]);

    [$code, $output] = runVerifyData(['--strict' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('--strict なので失敗に数える')
        ->and($output)->not->toContain('失敗に数える破れは無い');
});

it('TC-157-11: --strict でも、警告が無ければ 0 で終わる', function () {
    verifyDataFixture();

    [$code] = runVerifyData(['--strict' => true]);

    expect($code)->toBe(0);
});

it('TC-157-12: 破れが複数あれば、種類の違う破れをすべて挙げる', function () {
    $data = verifyDataFixture();
    Cocktail::query()->whereKey($data['cocktails']['ライムだけ']->id)->update(['recipe_count' => 5]);
    Cocktail::query()->whereKey($data['cocktails']['ライムだけ']->id)->update(['base_category_id' => $data['nodes']['その他']->id]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('整合性が破れている(2 件)')
        ->and($output)->toContain('材料の数(recipe_count)')
        ->and($output)->toContain('ベースに根');
});

it('TC-157-13: 調べるだけで、データを書き換えない', function () {
    $data = verifyDataFixture();
    Cocktail::query()->whereKey($data['cocktails']['ラムソーダ']->id)->update(['recipe_count' => 9]);
    $before = [
        DB::table('cocktails')->orderBy('id')->get()->toArray(),
        DB::table('cocktail_ingredients')->orderBy('cocktail_id')->orderBy('position')->get()->toArray(),
        DB::table('affiliates')->orderBy('id')->get()->toArray(),
        DB::table('categories')->orderBy('id')->get()->toArray(),
    ];

    runVerifyData(['--strict' => true]);

    expect([
        DB::table('cocktails')->orderBy('id')->get()->toArray(),
        DB::table('cocktail_ingredients')->orderBy('cocktail_id')->orderBy('position')->get()->toArray(),
        DB::table('affiliates')->orderBy('id')->get()->toArray(),
        DB::table('categories')->orderBy('id')->get()->toArray(),
    ])->toEqual($before);
});

it('TC-285-12: 説明文の参照(大文字を含む)がすべて指す先のあるものなら、問題なしで 0 で終わる', function () {
    $data = verifyDataFixture();
    $n = $data['nodes'];
    $rumSoda = $data['cocktails']['ラムソーダ'];
    $dictionary = Dictionary::factory()->create(['description' => '@n'.$n['ラム']->id.'@']);
    $method = Method::factory()->create(['description' => '@D'.$dictionary->id.'@']);
    $n['ジン']->update(['description' => "@n{$n['ラム']->id}@と@c{$rumSoda->id}@"]);
    $data['brands']['バカルディ']->update(['description' => "@N{$n['ホワイトラム']->id}@"]);
    $rumSoda->update(['description' => "@b{$data['brands']['ビーフィーター']->id}@", 'note' => "@d{$dictionary->id}@・@m@・a@example.com"]);
    expect($method->description)->toBe("@D{$dictionary->id}@");

    [$code, $output] = runVerifyData();

    expect($code)->toBe(0)
        ->and($output)->toContain('問題なし');
});

it('TC-285-13: 説明文の参照の指す先が無いと、どの表のどの行の列か・どの参照かを挙げて失敗する', function (string $table, string $column, string $label) {
    $data = verifyDataFixture();
    $row = match ($table) {
        'categories' => $data['nodes']['ジン'],
        'brands' => $data['brands']['バカルディ'],
        'cocktails' => $data['cocktails']['ラムソーダ'],
        'methods' => Method::factory()->create(),
        default => Dictionary::factory()->create(),
    };
    DB::table($table)->where('id', $row->id)->update([$column => '前@n999@・@C998@・@b997@・@d996@後']);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('整合性が破れている(4 件)')
        ->and($output)->toContain('説明文の参照の指す先が無い')
        ->and($output)->toContain("{$label} #{$row->id} {$row->name} の {$column}")
        ->and($output)->toContain('@n999@ の指す先のノード #999 が無い')
        ->and($output)->toContain('@C998@ の指す先のカクテル #998 が無い')
        ->and($output)->toContain('@b997@ の指す先の銘柄 #997 が無い')
        ->and($output)->toContain('@d996@ の指す先の用語 #996 が無い');
})->with([
    'ノードの説明' => ['categories', 'description', 'ノード'],
    '技法の説明' => ['methods', 'description', '技法'],
    '用語の説明' => ['dictionaries', 'description', '用語'],
    '銘柄の説明' => ['brands', 'description', '銘柄'],
    'カクテルの説明' => ['cocktails', 'description', 'カクテル'],
    'カクテルの補足' => ['cocktails', 'note', 'カクテル'],
]);

it('TC-285-14: 説明文に旧の記法(@p・@m)が残っていると、その記法を挙げて失敗する', function () {
    $data = verifyDataFixture();
    $rumSoda = $data['cocktails']['ラムソーダ'];
    // @c と @p が @ を 1 つ分け合う形(旧は 2 つの参照として読んでいた)も、@p が残ったものとして見つける
    $rumSoda->update(['description' => '@p1@と@M2@', 'note' => "@c{$rumSoda->id}@p3@"]);

    [$code, $output] = runVerifyData();

    expect($code)->toBe(1)
        ->and($output)->toContain('整合性が破れている(3 件)')
        ->and($output)->toContain('説明文に旧の記法')
        ->and($output)->toContain("カクテル #{$rumSoda->id} ラムソーダ の description")
        ->and($output)->toContain('旧の記法 @p1@ が残っている')
        ->and($output)->toContain('旧の記法 @M2@ が残っている')
        ->and($output)->toContain("カクテル #{$rumSoda->id} ラムソーダ の note")
        ->and($output)->toContain('旧の記法 @p3@ が残っている');
});
