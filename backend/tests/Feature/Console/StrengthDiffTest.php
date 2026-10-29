<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

// cocktails:strength-diff(#160)が、持っている度数と計算し直した度数が違うカクテルだけを一覧にし、何も書き換えないかを、
// MariaDB で確かめる。木は CategoryTree::addNode だけで組む(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * ジン(有名な銘柄 40%)とソーダ(有名な銘柄なし)のノードを作る。
 *
 * @return array{Category, Category}
 */
function strengthDiffNodes(): array
{
    $tree = app(CategoryTree::class);
    $attrs = fn (string $name, ?string $role = null): array => Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes();

    $gin = $tree->addNode($tree->addNode(null, $attrs('洋酒')), $attrs('ジン'));
    $soda = $tree->addNode($tree->addNode(null, $attrs('ソフトドリンク', 'soft_drink')), $attrs('ソーダ'));
    $famous = Brand::factory()->create(['category_id' => $gin->id, 'volume' => 40.0]);
    $gin->update(['famous_brand_id' => $famous->id]);

    return [$gin, $soda];
}

/**
 * 度数 $volume を持ち、材料が [ノード, 量] のカクテルを作る。
 *
 * @param  list<array{Category, string}>  $ingredients
 */
function strengthDiffCocktail(string $name, float $volume, array $ingredients): Cocktail
{
    $cocktail = Cocktail::factory()->create(['name' => $name, 'volume' => $volume]);
    foreach ($ingredients as $position => [$node, $amount]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'position' => $position + 1,
            'category_id' => $node->id,
            'amount' => $amount,
        ]);
    }

    return $cocktail;
}

/**
 * cocktails:strength-diff を流し、終了コードと出力を返す。
 *
 * @return array{int, string}
 */
function runStrengthDiff(): array
{
    $code = Artisan::call('cocktails:strength-diff');

    return [$code, Artisan::output()];
}

it('TC-160-13: 持っている度数と計算した度数が違うカクテルだけを、両方の度数と一緒に一覧にする', function () {
    [$gin, $soda] = strengthDiffNodes();
    // 計算すると 20.0
    $same = strengthDiffCocktail('同じカクテル', 20.0, [[$gin, '30ml'], [$soda, '30ml']]);
    $stale = strengthDiffCocktail('古いカクテル', 33.0, [[$gin, '30ml'], [$soda, '30ml']]);

    [$code, $output] = runStrengthDiff();

    expect($code)->toBe(0)
        ->and($output)->toContain('違うカクテル: 1 件(カクテル 2 件中)')
        ->and($output)->toMatch("/\\|\\s*{$stale->id}\\s*\\|\\s*古いカクテル\\s*\\|\\s*33\\.0\\s*\\|\\s*20\\.0\\s*\\|/u")
        ->and($output)->not->toContain('同じカクテル')
        ->and($same->id)->not->toBe($stale->id);
});

it('TC-160-14: 一覧にしても、カクテルの度数・更新日時を書き換えない', function () {
    [$gin, $soda] = strengthDiffNodes();
    $stale = strengthDiffCocktail('古いカクテル', 33.0, [[$gin, '30ml'], [$soda, '30ml']]);
    $before = Cocktail::query()->findOrFail($stale->id)->only(['volume', 'updated_at']);

    runStrengthDiff();

    expect(Cocktail::query()->findOrFail($stale->id)->only(['volume', 'updated_at']))->toEqual($before);
});

it('TC-160-15: 度数を計算できないカクテルも「計算できない」として一覧に出し、ほかのカクテルは続けて調べる', function () {
    [$gin, $soda] = strengthDiffNodes();
    $broken = strengthDiffCocktail('計算できないカクテル', 0.0, [[$gin, '1tsp'], [$soda, '1dash']]);
    $stale = strengthDiffCocktail('古いカクテル', 33.0, [[$gin, '30ml'], [$soda, '30ml']]);

    [$code, $output] = runStrengthDiff();

    expect($code)->toBe(0)
        ->and($output)->toContain('違うカクテル: 2 件(カクテル 2 件中)')
        ->and($output)->toMatch("/\\|\\s*{$broken->id}\\s*\\|\\s*計算できないカクテル\\s*\\|\\s*0\\.0\\s*\\|\\s*計算できない: /u")
        ->and($output)->toMatch("/\\|\\s*{$stale->id}\\s*\\|\\s*古いカクテル\\s*\\|\\s*33\\.0\\s*\\|\\s*20\\.0\\s*\\|/u");
});

it('TC-160-16: すべて同じなら、違いなしと件数を出して 0 で終わる', function () {
    [$gin, $soda] = strengthDiffNodes();
    strengthDiffCocktail('同じカクテル', 20.0, [[$gin, '30ml'], [$soda, '30ml']]);
    // 適量だけのカクテルは丸めずに計算する(13.33…)ので、持っている値(小数 1 桁)と小数 1 桁で比べる
    strengthDiffCocktail('適量だけのカクテル', 13.3, [[$gin, '適量'], [$soda, '適量'], [$soda, '-']]);

    [$code, $output] = runStrengthDiff();

    expect($code)->toBe(0)
        ->and($output)->toContain('違いなし(カクテル 2 件)');
});
