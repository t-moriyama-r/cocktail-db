<?php

use App\Enums\CategoryDeleteBlocker;
use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryNotDeletableException;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;

// CategoryTree::deleteLeaf(葉の削除。#91。消せない条件は docs/schema.md の「カテゴリを消すとき」)を、MariaDB の閉包表で確かめる。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 消す対象の葉と、そのまわりのノードを作る。
 *
 * 根 ─ 親 ─ 葉
 *        └ 兄弟
 * 別の根
 *
 * @return array<string, Category>
 */
function deleteLeafTree(): array
{
    $tree = app(CategoryTree::class);
    $nodes = ['根' => $tree->addNode(null, deleteLeafAttrs(['name' => '根']))];
    $nodes['親'] = $tree->addNode($nodes['根'], deleteLeafAttrs(['name' => '親']));
    $nodes['葉'] = $tree->addNode($nodes['親'], deleteLeafAttrs(['name' => '葉']));
    $nodes['兄弟'] = $tree->addNode($nodes['親'], deleteLeafAttrs(['name' => '兄弟']));
    $nodes['別の根'] = $tree->addNode(null, deleteLeafAttrs(['name' => '別の根']));

    return $nodes;
}

/**
 * addNode に渡す、保存していないノードの属性。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function deleteLeafAttrs(array $overrides = []): array
{
    return Category::factory()->make($overrides)->getAttributes();
}

/**
 * 消せない理由を 1 つ、$node に作る。
 */
function deleteLeafAddBlocker(Category $node, CategoryDeleteBlocker $blocker): void
{
    match ($blocker) {
        CategoryDeleteBlocker::Children => app(CategoryTree::class)->addNode($node, deleteLeafAttrs()),
        CategoryDeleteBlocker::Brands => Brand::factory()->create(['category_id' => $node->id]),
        CategoryDeleteBlocker::Ingredients => CocktailIngredient::factory()->create(['category_id' => $node->id, 'position' => 1]),
        CategoryDeleteBlocker::BaseCocktails => Cocktail::factory()->create(['base_category_id' => $node->id]),
        CategoryDeleteBlocker::Affiliates => Affiliate::factory()->create(['category_id' => $node->id]),
    };
}

/**
 * 閉包表のすべての行を [祖先の ID, 子孫の ID, depth] で、並びを決めて返す。
 *
 * @return list<array{int, int, int}>
 */
function deleteLeafClosureRows(): array
{
    return array_values(CategoryClosure::query()
        ->orderBy('ancestor_id')
        ->orderBy('descendant_id')
        ->get()
        ->map(fn (CategoryClosure $row): array => [$row->ancestor_id, $row->descendant_id, $row->depth])
        ->all());
}

/**
 * deleteLeaf が CategoryNotDeletableException を投げることを確かめ、載っていた理由を返す。
 *
 * @return list<CategoryDeleteBlocker>
 */
function deleteLeafRefusedBlockers(Category $node): array
{
    try {
        app(CategoryTree::class)->deleteLeaf($node);
    } catch (CategoryNotDeletableException $e) {
        return $e->blockers;
    }

    throw new RuntimeException('deleteLeaf が CategoryNotDeletableException を投げなかった');
}

it('TC-91-01: 子・銘柄・材料・ベース・アフィリエイトのどれも無い葉は消え、閉包表からは葉を指す行だけが消える', function () {
    $nodes = deleteLeafTree();
    $leafId = $nodes['葉']->id;
    $rowsBefore = deleteLeafClosureRows();

    app(CategoryTree::class)->deleteLeaf($nodes['葉']);

    expect(Category::query()->whereKey($leafId)->exists())->toBeFalse()
        ->and(deleteLeafClosureRows())->toBe(array_values(array_filter(
            $rowsBefore,
            fn (array $row): bool => $row[0] !== $leafId && $row[1] !== $leafId,
        )))
        ->and(count($rowsBefore) - count(deleteLeafClosureRows()))->toBe(3); // 自分・親・根からの 3 行
});

it('TC-91-02〜06: 消せない理由が 1 つだけあると消せず、その理由だけを返し、ノードも閉包表も変わらない', function (CategoryDeleteBlocker $blocker) {
    $nodes = deleteLeafTree();
    deleteLeafAddBlocker($nodes['葉'], $blocker);
    $rowsBefore = deleteLeafClosureRows();

    expect(deleteLeafRefusedBlockers($nodes['葉']))->toBe([$blocker])
        ->and(Category::query()->whereKey($nodes['葉']->id)->exists())->toBeTrue()
        ->and(deleteLeafClosureRows())->toBe($rowsBefore);
})->with([
    'TC-91-02: 子' => [CategoryDeleteBlocker::Children],
    'TC-91-03: 銘柄' => [CategoryDeleteBlocker::Brands],
    'TC-91-04: 材料' => [CategoryDeleteBlocker::Ingredients],
    'TC-91-05: ベース' => [CategoryDeleteBlocker::BaseCocktails],
    'TC-91-06: アフィリエイト' => [CategoryDeleteBlocker::Affiliates],
]);

it('TC-91-07: 消せない理由がすべてあると、すべての理由を定義の順で返し、ノードも閉包表も変わらない', function () {
    $nodes = deleteLeafTree();
    foreach ([
        CategoryDeleteBlocker::Affiliates,
        CategoryDeleteBlocker::BaseCocktails,
        CategoryDeleteBlocker::Ingredients,
        CategoryDeleteBlocker::Brands,
        CategoryDeleteBlocker::Children,
    ] as $blocker) {
        deleteLeafAddBlocker($nodes['葉'], $blocker);
    }
    $rowsBefore = deleteLeafClosureRows();

    expect(deleteLeafRefusedBlockers($nodes['葉']))->toBe(CategoryDeleteBlocker::cases())
        ->and(Category::query()->whereKey($nodes['葉']->id)->exists())->toBeTrue()
        ->and(deleteLeafClosureRows())->toBe($rowsBefore);
});

it('TC-91-08: 子孫にだけ銘柄があるノードの理由は、子があることだけ(子孫の銘柄は見ない)', function () {
    $nodes = deleteLeafTree();
    deleteLeafAddBlocker($nodes['葉'], CategoryDeleteBlocker::Brands);

    expect(deleteLeafRefusedBlockers($nodes['親']))->toBe([CategoryDeleteBlocker::Children])
        ->and(deleteLeafRefusedBlockers($nodes['根']))->toBe([CategoryDeleteBlocker::Children]);
});

it('TC-91-09: ほかのノード(兄弟・別の根)に理由があっても、葉は消せる', function () {
    $nodes = deleteLeafTree();
    foreach (CategoryDeleteBlocker::cases() as $blocker) {
        deleteLeafAddBlocker($nodes['兄弟'], $blocker);
        deleteLeafAddBlocker($nodes['別の根'], $blocker);
    }

    app(CategoryTree::class)->deleteLeaf($nodes['葉']);

    expect(Category::query()->whereKey($nodes['葉']->id)->exists())->toBeFalse();
});

it('TC-91-10: 子の無い根は消え、roots から外れる', function () {
    $nodes = deleteLeafTree();
    $tree = app(CategoryTree::class);

    $tree->deleteLeaf($nodes['別の根']);

    expect($tree->roots()->pluck('id')->all())->toBe([$nodes['根']->id])
        ->and(CategoryClosure::query()->where('ancestor_id', $nodes['別の根']->id)->orWhere('descendant_id', $nodes['別の根']->id)->exists())->toBeFalse();
});

it('TC-91-11: 子をすべて消すと親は葉になって消せ、その親の children から消したノードが外れる', function () {
    $nodes = deleteLeafTree();
    $tree = app(CategoryTree::class);

    $tree->deleteLeaf($nodes['葉']);
    expect($tree->children($nodes['親'])->pluck('id')->all())->toBe([$nodes['兄弟']->id])
        ->and(deleteLeafRefusedBlockers($nodes['親']))->toBe([CategoryDeleteBlocker::Children]);

    $tree->deleteLeaf($nodes['兄弟']);
    expect($tree->deleteBlockers($nodes['親']))->toBe([]);

    $tree->deleteLeaf($nodes['親']);
    expect($tree->children($nodes['根'])->all())->toBe([])
        ->and(Category::query()->whereKey($nodes['親']->id)->exists())->toBeFalse();
});

it('TC-91-12: 木に入っていないノード・保存していないノードは消せず、ノードも閉包表も変わらない', function () {
    $nodes = deleteLeafTree();
    $outside = Category::factory()->create();
    $rowsBefore = deleteLeafClosureRows();
    $tree = app(CategoryTree::class);

    expect(fn () => $tree->deleteLeaf($outside))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $tree->deleteLeaf(Category::factory()->make()))->toThrow(InvalidArgumentException::class)
        ->and(Category::query()->whereKey($outside->id)->exists())->toBeTrue()
        ->and(Category::query()->count())->toBe(count($nodes) + 1)
        ->and(deleteLeafClosureRows())->toBe($rowsBefore);
});

it('TC-91-13: deleteBlockers は消せる葉では空、理由があれば deleteLeaf と同じ理由を返し、何も消さない', function () {
    $nodes = deleteLeafTree();
    $tree = app(CategoryTree::class);
    expect($tree->deleteBlockers($nodes['葉']))->toBe([]);

    deleteLeafAddBlocker($nodes['葉'], CategoryDeleteBlocker::Ingredients);
    deleteLeafAddBlocker($nodes['葉'], CategoryDeleteBlocker::Brands);

    expect($tree->deleteBlockers($nodes['葉']))->toBe([CategoryDeleteBlocker::Brands, CategoryDeleteBlocker::Ingredients])
        ->and(deleteLeafRefusedBlockers($nodes['葉']))->toBe($tree->deleteBlockers($nodes['葉']))
        ->and(Category::query()->whereKey($nodes['葉']->id)->exists())->toBeTrue();
});
