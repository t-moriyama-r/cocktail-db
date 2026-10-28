<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// cocktails:recount(#74。旧 sys/genrec の SystemController::insertRecipeCols)を、MariaDB で確かめる。
// 数え方は RecipeCounter(#156)のテストで確かめているので、ここではすべてのカクテルを数え直して書くこと・
// 値が変わったカクテルだけ更新日時が今になること(旧は Eloquent の save で書いていた)を見る。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 洋酒(根)の下にラム、その他(根。other)の下にライムを置いた木と、次のカクテル。
 *
 * - ラムライム: ラム・ライム(任意でない)。材料の数 1
 * - ラム 2 杯: ラム・ラム(任意でない)。材料の数 2
 * - 材料なし: 材料の行が無い。材料の数 0
 *
 * @return array<string, Cocktail>
 */
function recountCommandFixture(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes(),
    );
    $spirits = $add(null, '洋酒');
    $rum = $add($spirits, 'ラム');
    $other = $add(null, 'その他', 'other');
    $lime = $add($other, 'ライム');

    $cocktails = [];
    foreach (['ラムライム' => [$rum, $lime], 'ラム 2 杯' => [$rum, $rum], '材料なし' => []] as $name => $nodes) {
        $cocktail = Cocktail::factory()->create(['name' => $name]);
        foreach ($nodes as $position => $node) {
            CocktailIngredient::factory()->create([
                'cocktail_id' => $cocktail->id,
                'position' => $position + 1,
                'category_id' => $node->id,
                'is_optional' => false,
            ]);
        }
        $cocktails[$name] = $cocktail;
    }

    return $cocktails;
}

/**
 * RecipeCounter を通さずに、列 recipe_count と updated_at を直接書く(数え直していない状態を作れるのはテストだけ)。
 */
function recountCommandSetStored(Cocktail $cocktail, int $recipeCount, string $updatedAt): void
{
    DB::table('cocktails')->where('id', $cocktail->id)->update(['recipe_count' => $recipeCount, 'updated_at' => $updatedAt]);
}

/**
 * いまの列の値(updated_at は DB に入っている文字列のまま)。
 *
 * @return array{recipe_count: int, updated_at: mixed}
 */
function recountCommandStored(Cocktail $cocktail): array
{
    return [
        'recipe_count' => Cocktail::query()->findOrFail($cocktail->id)->recipe_count,
        'updated_at' => DB::table('cocktails')->where('id', $cocktail->id)->value('updated_at'),
    ];
}

it('TC-74-01: すべてのカクテルの recipe_count を数え直して書き、材料の無いカクテルは 0 にする', function () {
    $c = recountCommandFixture();
    foreach ($c as $cocktail) {
        recountCommandSetStored($cocktail, 9, '2020-01-01 00:00:00');
    }

    $code = Artisan::call('cocktails:recount');

    expect($code)->toBe(0)
        ->and(recountCommandStored($c['ラムライム'])['recipe_count'])->toBe(1)
        ->and(recountCommandStored($c['ラム 2 杯'])['recipe_count'])->toBe(2)
        ->and(recountCommandStored($c['材料なし'])['recipe_count'])->toBe(0);
});

it('TC-74-02: 値が変わったカクテルだけ updated_at を実行した時刻にし、変わらないカクテルの updated_at は残す', function () {
    $c = recountCommandFixture();
    recountCommandSetStored($c['ラムライム'], 2, '2020-01-01 00:00:00');
    recountCommandSetStored($c['ラム 2 杯'], 2, '2020-01-02 00:00:00');
    recountCommandSetStored($c['材料なし'], 0, '2020-01-03 00:00:00');
    $this->travelTo('2026-10-28 12:34:56');

    Artisan::call('cocktails:recount');

    expect(recountCommandStored($c['ラムライム']))->toBe(['recipe_count' => 1, 'updated_at' => '2026-10-28 12:34:56'])
        ->and(recountCommandStored($c['ラム 2 杯']))->toBe(['recipe_count' => 2, 'updated_at' => '2020-01-02 00:00:00'])
        ->and(recountCommandStored($c['材料なし']))->toBe(['recipe_count' => 0, 'updated_at' => '2020-01-03 00:00:00']);
});

it('TC-74-03: 数え直した件数と、値が変わった件数を出す', function () {
    $c = recountCommandFixture();
    recountCommandSetStored($c['ラムライム'], 2, '2020-01-01 00:00:00');
    recountCommandSetStored($c['ラム 2 杯'], 2, '2020-01-01 00:00:00');
    recountCommandSetStored($c['材料なし'], 5, '2020-01-01 00:00:00');

    $code = Artisan::call('cocktails:recount');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('材料の数を数え直した: 3 件(値が変わった: 2 件)');
});

it('TC-74-04: カクテルが 1 件も無くても失敗せず、0 件と出す', function () {
    $code = Artisan::call('cocktails:recount');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('材料の数を数え直した: 0 件(値が変わった: 0 件)');
});
