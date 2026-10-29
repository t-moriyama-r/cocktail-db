<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ランダムウォークの API(GET /api/random-walk。#45)。
// 期待値は、旧 CommonController@RandomWalk(本番の laravel13)のソースと、docs/decisions/public-detail.md の旧との差分から置く。
// 旧システムを動かす環境が無いので、旧での実測は取っていない(PR 本文の表に「未確認」と書いた)。
//
// 選ぶのはランダムなので、同じリクエストを何度も送り、出た行き先の集まり・回数で確かめる。回数は、たまたま落ちる確率を
// それぞれのテストのコメントに書いた。

uses(RefreshDatabase::class)->group('db');

/**
 * ランダムウォークを $times 回呼び、返った行き先を並べる。
 *
 * @return list<array{type: string, id: int}>
 */
function randomWalks(TestCase $test, int $times): array
{
    $walks = [];
    for ($i = 0; $i < $times; $i++) {
        /** @var array{type: string, id: int} $walk */
        $walk = $test->getJson('/api/random-walk')->assertOk()->json();
        $walks[] = $walk;
    }

    return $walks;
}

/**
 * 行き先のうち、種類が $type のものの ID を、重なりを除いて小さい順に返す。
 *
 * @param  list<array{type: string, id: int}>  $walks
 * @return list<int>
 */
function walkedIds(array $walks, string $type): array
{
    $ids = array_unique(array_map(fn (array $walk): int => $walk['id'], array_filter($walks, fn (array $walk): bool => $walk['type'] === $type)));
    sort($ids);

    return $ids;
}

/**
 * 根・子・孫の 3 段の木を作る。洋酒(根) └ ラム └ ホワイトラム。
 *
 * @return array{root: Category, child: Category, grandchild: Category}
 */
function randomWalkTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name])->getAttributes(),
    );

    $root = $add(null, '洋酒');
    $child = $add($root, 'ラム');
    $grandchild = $add($child, 'ホワイトラム');

    return ['root' => $root, 'child' => $child, 'grandchild' => $grandchild];
}

it('TC-45-01: 応答は {type, id} で、type は cocktail・category・brand のどれか。id はその種類にある', function () {
    $nodes = randomWalkTree();
    $cocktail = Cocktail::factory()->create(['base_category_id' => $nodes['child']->id]);
    $brand = Brand::factory()->create(['category_id' => $nodes['grandchild']->id]);

    // 3 種類のどれかが 60 回で 1 度も出ない確率は 3 × (2/3)^60 ≒ 1e-10
    $walks = randomWalks($this, 60);

    foreach ($walks as $walk) {
        expect(array_keys($walk))->toBe(['type', 'id']);
    }
    // toBe は型まで比べるので、id が数で返ることもここで確かめる
    expect(collect($walks)->pluck('type')->unique()->sort()->values()->all())->toBe(['brand', 'category', 'cocktail'])
        ->and(walkedIds($walks, 'cocktail'))->toBe([$cocktail->id])
        ->and(walkedIds($walks, 'brand'))->toBe([$brand->id]);
});

it('TC-45-02: カクテルは、公開しているものだけから選ぶ(下書きは選ばない)', function () {
    $published = Cocktail::factory()->create();
    Cocktail::factory()->count(3)->create(['is_draft' => true]);
    Brand::factory()->create();
    randomWalkTree();

    // カクテルが 90 回で 1 度も選ばれない確率は (2/3)^90 ≒ 1e-16
    $walks = randomWalks($this, 90);

    expect(walkedIds($walks, 'cocktail'))->toBe([$published->id]);
});

it('TC-45-03: ノードは、根を選ばず、根でないノードをどの深さからも選ぶ', function () {
    $nodes = randomWalkTree();
    Cocktail::factory()->create(['base_category_id' => $nodes['child']->id]);
    Brand::factory()->create(['category_id' => $nodes['child']->id]);

    // 子か孫のどちらかが 120 回で 1 度も選ばれない確率は 2 × (5/6)^120 ≒ 6e-10
    $walks = randomWalks($this, 120);

    // 根(洋酒)は出ない。カクテル・銘柄の factory が作る単体のノードも、親を持たない根なので出ない
    expect(walkedIds($walks, 'category'))->toBe([$nodes['child']->id, $nodes['grandchild']->id]);
});

it('TC-45-04: 1 つの種類の中では、すべての行から選ぶ', function () {
    $nodes = randomWalkTree();
    Cocktail::factory()->create(['base_category_id' => $nodes['child']->id]);
    $brands = Brand::factory()->count(3)->create(['category_id' => $nodes['child']->id]);

    // 3 件の銘柄のどれかが 200 回で 1 度も選ばれない確率は 3 × (8/9)^200 ≒ 2e-10
    $walks = randomWalks($this, 200);

    expect(walkedIds($walks, 'brand'))->toBe($brands->pluck('id')->sort()->values()->all());
});

it('TC-45-05: 種類は 3 分の 1 ずつで選ぶ(ノードを 2/4 にしない)', function () {
    $nodes = randomWalkTree();
    Cocktail::factory()->create(['base_category_id' => $nodes['child']->id]);
    Brand::factory()->create(['category_id' => $nodes['child']->id]);

    // 300 回で、どの種類も 100 回 ± 40 回に入る。外れる確率は 3 種類で合わせて 1e-5 ほど(標準偏差 8.2 回の 4.9 倍)
    $counts = collect(randomWalks($this, 300))->countBy('type');

    foreach (['cocktail', 'category', 'brand'] as $type) {
        expect($counts->get($type, 0))->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(140);
    }
});

it('TC-45-06: 選んだ種類に 1 件も無ければ 404', function () {
    // カクテルは下書きだけ、ノードは根だけ、銘柄は無い。どの種類を選んでも行き先が無い
    Cocktail::factory()->create(['is_draft' => true]);
    app(CategoryTree::class)->addNode(null, Category::factory()->make(['name' => '洋酒'])->getAttributes());

    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/api/random-walk')->assertNotFound();
    }
});
