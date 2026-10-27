<?php

use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// CategoryTree のノードの追加と参照(#89)・部分木の移動(#90)を、MariaDB の閉包表で確かめる。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * addNode に渡す、保存していないノードの属性。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function categoryTreeAttrs(array $overrides = []): array
{
    return Category::factory()->make($overrides)->getAttributes();
}

/**
 * 根から 1 本につながる、深さ $depth の鎖を作る。返すのは根から順のノード($depth + 1 個)。
 *
 * @return list<Category>
 */
function categoryTreeChain(int $depth): array
{
    $tree = app(CategoryTree::class);
    $nodes = [$tree->addNode(null, categoryTreeAttrs())];
    for ($i = 1; $i <= $depth; $i++) {
        $nodes[] = $tree->addNode($nodes[$i - 1], categoryTreeAttrs());
    }

    return $nodes;
}

/**
 * 閉包表のすべての行を [祖先の ID, 子孫の ID, depth] で返す。
 *
 * @return list<array{int, int, int}>
 */
function categoryTreeClosureRows(): array
{
    return array_values(CategoryClosure::query()
        ->get()
        ->map(fn (CategoryClosure $row): array => [$row->ancestor_id, $row->descendant_id, $row->depth])
        ->all());
}

/**
 * 鎖の閉包表として正しい行(i 番目から j 番目へ、depth j - i)を返す。
 *
 * @param  list<Category>  $chain
 * @return list<array{int, int, int}>
 */
function categoryTreeExpectedChainRows(array $chain): array
{
    $rows = [];
    foreach ($chain as $i => $ancestor) {
        foreach ($chain as $j => $descendant) {
            if ($j >= $i) {
                $rows[] = [$ancestor->id, $descendant->id, $j - $i];
            }
        }
    }

    return $rows;
}

/**
 * リレーションが返すノードの ID をキーに、閉包表の depth(pivot)を引く。
 *
 * @param  BelongsToMany<Category, Category, CategoryClosure>  $relation
 * @return array<int, int|null>
 */
function categoryTreeDepthById(BelongsToMany $relation): array
{
    $depths = [];
    foreach ($relation->get() as $category) {
        $pivot = $category->getRelation('pivot');
        $depths[$category->id] = $pivot instanceof CategoryClosure ? $pivot->depth : null;
    }
    ksort($depths);

    return $depths;
}

dataset('chain depths', [
    'TC-89-01: 根だけ(深さ 0)' => [0, 1],
    'TC-89-02: 深さ 1' => [1, 3],
    'TC-89-03: 深さ 2' => [2, 6],
    'TC-89-04: 深さ 5' => [5, 21],
    'TC-89-05: 深さ 6' => [6, 28],
]);

it('TC-89-01〜05: 鎖を作ると、閉包表の行数が各ノードの深さ + 1 の合計になり、行はすべての祖先からの段数と一致する', function (int $depth, int $expectedRows) {
    $chain = categoryTreeChain($depth);

    expect(DB::table('category_closures')->count())->toBe($expectedRows)
        ->and(categoryTreeClosureRows())->toEqualCanonicalizing(categoryTreeExpectedChainRows($chain));
})->with('chain depths');

it('TC-89-06: 枝分かれした木と別の根を作ると、閉包表は各ノードの祖先からの行だけになり、別の木の行は増えない', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, categoryTreeAttrs());
    $a = $tree->addNode($root, categoryTreeAttrs());
    $b = $tree->addNode($root, categoryTreeAttrs());
    $other = $tree->addNode(null, categoryTreeAttrs());
    $a1 = $tree->addNode($a, categoryTreeAttrs());
    $otherChild = $tree->addNode($other, categoryTreeAttrs());

    $expected = [
        [$root->id, $root->id, 0], [$root->id, $a->id, 1], [$root->id, $b->id, 1], [$root->id, $a1->id, 2],
        [$a->id, $a->id, 0], [$a->id, $a1->id, 1],
        [$b->id, $b->id, 0],
        [$other->id, $other->id, 0], [$other->id, $otherChild->id, 1],
        [$a1->id, $a1->id, 0],
        [$otherChild->id, $otherChild->id, 0],
    ];

    // 深さ 0・1・1・0・2・1 の 6 ノードで、行数は 1 + 2 + 2 + 1 + 3 + 2 = 11
    expect(categoryTreeClosureRows())->toEqualCanonicalizing($expected);
});

it('TC-89-07: addNode は渡した属性で保存したノードを返す', function () {
    $node = app(CategoryTree::class)->addNode(null, categoryTreeAttrs([
        'name' => 'ラム', 'name_search' => 'らむ', 'name_eng' => 'Rum', 'sort_order' => 3,
    ]));

    expect($node->exists)->toBeTrue()
        ->and(Category::findOrFail($node->id)->only(['name', 'name_search', 'name_eng', 'sort_order']))
        ->toBe(['name' => 'ラム', 'name_search' => 'らむ', 'name_eng' => 'Rum', 'sort_order' => 3]);
});

it('TC-89-08: 木に入っていない親(閉包表に自分の行が無い)の下には足せず、ノードも閉包表の行も残らない', function () {
    $root = app(CategoryTree::class)->addNode(null, categoryTreeAttrs());
    $outside = Category::factory()->create();
    $categoriesBefore = Category::count();
    $rowsBefore = categoryTreeClosureRows();

    expect(fn () => app(CategoryTree::class)->addNode($outside, categoryTreeAttrs()))
        ->toThrow(InvalidArgumentException::class);

    expect(Category::count())->toBe($categoriesBefore)
        ->and(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore)
        ->and($rowsBefore)->toBe([[$root->id, $root->id, 0]]);
});

it('TC-89-09: 保存していない親の下には足せず、ノードも閉包表の行も残らない', function () {
    $unsaved = Category::factory()->make();

    expect(fn () => app(CategoryTree::class)->addNode($unsaved, categoryTreeAttrs()))
        ->toThrow(InvalidArgumentException::class);

    expect(Category::count())->toBe(0)
        ->and(categoryTreeClosureRows())->toBe([]);
});

it('TC-89-10: path は根から自分までを根の側から順に返す(深さ 5 の葉)', function () {
    $chain = categoryTreeChain(5);

    expect(app(CategoryTree::class)->path($chain[5])->pluck('id')->all())
        ->toBe(array_map(fn (Category $c) => $c->id, $chain));
});

it('TC-89-11: 根の path は根だけ', function () {
    [$root] = categoryTreeChain(1);

    expect(app(CategoryTree::class)->path($root)->pluck('id')->all())->toBe([$root->id]);
});

it('TC-89-12: subtreeIds は自分とすべての子孫の ID を返し、親・兄弟・別の木を含まない', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, categoryTreeAttrs());
    $mid = $tree->addNode($root, categoryTreeAttrs());
    $sibling = $tree->addNode($root, categoryTreeAttrs());
    $child = $tree->addNode($mid, categoryTreeAttrs());
    $grandchild = $tree->addNode($child, categoryTreeAttrs());
    $tree->addNode(null, categoryTreeAttrs());

    expect($tree->subtreeIds($mid))->toEqualCanonicalizing([$mid->id, $child->id, $grandchild->id])
        ->and($tree->subtreeIds($mid))->not->toContain($root->id, $sibling->id);
});

it('TC-89-13: 葉の subtreeIds は自分だけ', function () {
    $chain = categoryTreeChain(2);

    expect(app(CategoryTree::class)->subtreeIds($chain[2]))->toBe([$chain[2]->id]);
});

it('TC-89-14: roots は根だけを sort_order の順に返し、sort_order が同じなら ID の順', function () {
    $tree = app(CategoryTree::class);
    $r2 = $tree->addNode(null, categoryTreeAttrs(['sort_order' => 2]));
    $r1a = $tree->addNode(null, categoryTreeAttrs(['sort_order' => 1]));
    $r1b = $tree->addNode(null, categoryTreeAttrs(['sort_order' => 1]));
    $tree->addNode($r1a, categoryTreeAttrs(['sort_order' => 0]));

    expect($tree->roots()->pluck('id')->all())->toBe([$r1a->id, $r1b->id, $r2->id]);
});

it('TC-89-15: ノードが 1 つも無いとき roots は空', function () {
    expect(app(CategoryTree::class)->roots()->all())->toBe([]);
});

it('TC-89-16: children はすぐ下の子だけを sort_order の順に返し、sort_order が同じなら ID の順で、孫を含まない', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, categoryTreeAttrs());
    $c3 = $tree->addNode($root, categoryTreeAttrs(['sort_order' => 3]));
    $c1a = $tree->addNode($root, categoryTreeAttrs(['sort_order' => 1]));
    $c1b = $tree->addNode($root, categoryTreeAttrs(['sort_order' => 1]));
    $tree->addNode($c1a, categoryTreeAttrs(['sort_order' => 0]));

    expect($tree->children($root)->pluck('id')->all())->toBe([$c1a->id, $c1b->id, $c3->id]);
});

it('TC-89-17: 葉の children は空', function () {
    $chain = categoryTreeChain(1);

    expect(app(CategoryTree::class)->children($chain[1])->all())->toBe([]);
});

it('TC-21-13: CategoryTree で組んだ木で、Category の parent・children・ancestors・descendants が正しいノードと段数を返す', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, categoryTreeAttrs());
    $mid = $tree->addNode($root, categoryTreeAttrs());
    $sibling = $tree->addNode($root, categoryTreeAttrs());
    $leaf = $tree->addNode($mid, categoryTreeAttrs());

    expect($root->parent)->toBeNull()
        ->and($mid->parent?->id)->toBe($root->id)
        ->and($leaf->parent?->id)->toBe($mid->id)
        ->and($root->children->pluck('id')->sort()->values()->all())->toBe([$mid->id, $sibling->id])
        ->and($mid->children->pluck('id')->all())->toBe([$leaf->id])
        ->and($leaf->children->all())->toBe([])
        ->and(categoryTreeDepthById($leaf->ancestors()))->toBe([$root->id => 2, $mid->id => 1])
        ->and(categoryTreeDepthById($root->ancestors()))->toBe([])
        ->and(categoryTreeDepthById($root->descendants()))->toBe([$mid->id => 1, $sibling->id => 1, $leaf->id => 2])
        ->and(categoryTreeDepthById($leaf->descendants()))->toBe([]);
});

// ---- 部分木の移動(#90) ----

/**
 * 移動のテストに使う木。キーはノードの名前、値は親のキー(根は null)。親が先に来る順に並べる。
 *
 * r1 ─ a ─ a1 ─ a11
 *    │   └ a2
 *    └ b ─ b1 ─ b11 ─ b111 ─ b1111(深さ 5)
 * r2 ─ c ─ c1
 *
 * @return array<string, string|null>
 */
function categoryTreeMoveFixture(): array
{
    return [
        'r1' => null, 'a' => 'r1', 'a1' => 'a', 'a11' => 'a1', 'a2' => 'a',
        'b' => 'r1', 'b1' => 'b', 'b11' => 'b1', 'b111' => 'b11', 'b1111' => 'b111',
        'r2' => null, 'c' => 'r2', 'c1' => 'c',
    ];
}

/**
 * 親のキーの対応どおりに、CategoryTree::addNode で木を組む。
 *
 * @param  array<string, string|null>  $parentKeyByKey
 * @return array<string, Category>
 */
function categoryTreeBuild(array $parentKeyByKey): array
{
    $tree = app(CategoryTree::class);
    $nodes = [];
    foreach ($parentKeyByKey as $key => $parentKey) {
        $nodes[$key] = $tree->addNode($parentKey === null ? null : $nodes[$parentKey], categoryTreeAttrs());
    }

    return $nodes;
}

/**
 * 親のキーの対応だけから作り直した閉包表の行([祖先の ID, 子孫の ID, depth])。CategoryTree を通さずに数える。
 *
 * @param  array<string, string|null>  $parentKeyByKey
 * @param  array<string, Category>  $nodes
 * @return list<array{int, int, int}>
 */
function categoryTreeRebuiltRows(array $parentKeyByKey, array $nodes): array
{
    $rows = [];
    foreach (array_keys($parentKeyByKey) as $key) {
        $ancestorKey = $key;
        $depth = 0;
        while ($ancestorKey !== null) {
            $rows[] = [$nodes[$ancestorKey]->id, $nodes[$key]->id, $depth];
            $ancestorKey = $parentKeyByKey[$ancestorKey];
            $depth++;
        }
    }

    return $rows;
}

dataset('allowed moves', [
    'TC-90-01: 葉を別の木の中間ノードの下へ' => ['a2', 'c'],
    'TC-90-02: 子孫を持つ中間ノードを別の木の葉の下へ' => ['a', 'c1'],
    'TC-90-03: 中間ノードを深い位置(深さ 5 の葉)の下へ' => ['a1', 'b1111'],
    'TC-90-04: 葉を別の木の根の直下へ' => ['a11', 'r2'],
    'TC-90-05: 子孫を持つ中間ノードを根へ(null)' => ['b1', null],
    'TC-90-06: 根を部分木ごと別の木の葉の下へ' => ['r2', 'a2'],
]);

it('TC-90-01〜06: 部分木を移すと、閉包表が移した後の親子から作り直した閉包表とすべて一致する', function (string $nodeKey, ?string $newParentKey) {
    $parentKeyByKey = categoryTreeMoveFixture();
    $nodes = categoryTreeBuild($parentKeyByKey);

    app(CategoryTree::class)->moveSubtree($nodes[$nodeKey], $newParentKey === null ? null : $nodes[$newParentKey]);

    $parentKeyByKey[$nodeKey] = $newParentKey;
    expect(categoryTreeClosureRows())->toEqualCanonicalizing(categoryTreeRebuiltRows($parentKeyByKey, $nodes));
})->with('allowed moves');

it('TC-90-07・08: 今と同じ位置へ移しても、閉包表は前と同じ', function (string $nodeKey, ?string $newParentKey) {
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());
    $rowsBefore = categoryTreeClosureRows();

    app(CategoryTree::class)->moveSubtree($nodes[$nodeKey], $newParentKey === null ? null : $nodes[$newParentKey]);

    expect(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore);
})->with([
    'TC-90-07: 中間ノードを今と同じ親へ' => ['a1', 'a'],
    'TC-90-08: 根を根へ(null)' => ['r1', null],
]);

it('TC-90-09〜11: 自分自身・子・孫の下へは移せず、ノードも閉包表も変わらない', function (string $newParentKey) {
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());
    $categoriesBefore = Category::count();
    $rowsBefore = categoryTreeClosureRows();

    expect(fn () => app(CategoryTree::class)->moveSubtree($nodes['a'], $nodes[$newParentKey]))
        ->toThrow(InvalidArgumentException::class);

    expect(Category::count())->toBe($categoriesBefore)
        ->and(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore);
})->with([
    'TC-90-09: 自分自身' => ['a'],
    'TC-90-10: 子' => ['a1'],
    'TC-90-11: 孫' => ['a11'],
]);

it('TC-90-12: 部分木を移しても、移した部分木の中のノードを指さない行(兄弟・元の親・移した先・別の木)は変わらない', function () {
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());
    $movedIds = [$nodes['a1']->id, $nodes['a11']->id];
    $untouched = fn (): array => array_values(array_filter(
        categoryTreeClosureRows(),
        fn (array $row): bool => ! in_array($row[1], $movedIds, true),
    ));
    $untouchedBefore = $untouched();

    app(CategoryTree::class)->moveSubtree($nodes['a1'], $nodes['b']);

    expect($untouched())->toEqualCanonicalizing($untouchedBefore)
        ->and($untouchedBefore)->toContain(
            [$nodes['a']->id, $nodes['a2']->id, 1],
            [$nodes['b']->id, $nodes['b1111']->id, 4],
            [$nodes['r2']->id, $nodes['c1']->id, 2],
        );
});

it('TC-90-13: 木に入っていない新しい親(閉包表に自分の行が無い)の下へは移せず、閉包表は変わらない', function () {
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());
    $outside = Category::factory()->create();
    $rowsBefore = categoryTreeClosureRows();

    expect(fn () => app(CategoryTree::class)->moveSubtree($nodes['a'], $outside))
        ->toThrow(InvalidArgumentException::class);

    expect(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore);
});

it('TC-90-14: 保存していない新しい親の下へは移せず、閉包表は変わらない', function () {
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());
    $rowsBefore = categoryTreeClosureRows();

    expect(fn () => app(CategoryTree::class)->moveSubtree($nodes['a'], Category::factory()->make()))
        ->toThrow(InvalidArgumentException::class);

    expect(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore);
});

it('TC-90-15: 木に入っていないノード・保存していないノードは移せず、閉包表は変わらない', function () {
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());
    $outside = Category::factory()->create();
    $rowsBefore = categoryTreeClosureRows();

    expect(fn () => app(CategoryTree::class)->moveSubtree($outside, $nodes['a']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(CategoryTree::class)->moveSubtree(Category::factory()->make(), $nodes['a']))
        ->toThrow(InvalidArgumentException::class);

    expect(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore);
});

it('TC-90-16: 移した後の path・children・roots が新しい位置を返す', function () {
    $tree = app(CategoryTree::class);
    $nodes = categoryTreeBuild(categoryTreeMoveFixture());

    $tree->moveSubtree($nodes['a'], $nodes['c1']);
    $tree->moveSubtree($nodes['b'], null);

    $ids = fn (string ...$keys): array => array_map(fn (string $key): int => $nodes[$key]->id, $keys);
    expect($tree->path($nodes['a11'])->pluck('id')->all())->toBe($ids('r2', 'c', 'c1', 'a', 'a1', 'a11'))
        ->and($tree->children($nodes['r1'])->all())->toBe([])
        ->and($tree->children($nodes['c1'])->pluck('id')->all())->toBe($ids('a'))
        ->and($tree->roots()->pluck('id')->all())->toEqualCanonicalizing($ids('r1', 'b', 'r2'));
});
