<?php

use App\Models\Category;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// CategoryTree(#89)のノードの追加と参照を、MariaDB の閉包表で確かめる。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 根から 1 本につながる、深さ $depth の鎖を作る。返すのは根から順のノード($depth + 1 個)。
 *
 * @return list<Category>
 */
function categoryTreeChain(int $depth): array
{
    $tree = app(CategoryTree::class);
    $nodes = [$tree->addNode(null, Category::factory()->raw())];
    for ($i = 1; $i <= $depth; $i++) {
        $nodes[] = $tree->addNode($nodes[$i - 1], Category::factory()->raw());
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
    return DB::table('category_closures')
        ->get()
        ->map(fn (object $row) => [(int) $row->ancestor_id, (int) $row->descendant_id, (int) $row->depth])
        ->all();
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
    $root = $tree->addNode(null, Category::factory()->raw());
    $a = $tree->addNode($root, Category::factory()->raw());
    $b = $tree->addNode($root, Category::factory()->raw());
    $other = $tree->addNode(null, Category::factory()->raw());
    $a1 = $tree->addNode($a, Category::factory()->raw());
    $otherChild = $tree->addNode($other, Category::factory()->raw());

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
    $node = app(CategoryTree::class)->addNode(null, Category::factory()->raw([
        'name' => 'ラム', 'name_search' => 'らむ', 'name_eng' => 'Rum', 'sort_order' => 3,
    ]));

    expect($node->exists)->toBeTrue()
        ->and(Category::find($node->id)->only(['name', 'name_search', 'name_eng', 'sort_order']))
        ->toBe(['name' => 'ラム', 'name_search' => 'らむ', 'name_eng' => 'Rum', 'sort_order' => 3]);
});

it('TC-89-08: 木に入っていない親(閉包表に自分の行が無い)の下には足せず、ノードも閉包表の行も残らない', function () {
    $root = app(CategoryTree::class)->addNode(null, Category::factory()->raw());
    $outside = Category::factory()->create();
    $categoriesBefore = Category::count();
    $rowsBefore = categoryTreeClosureRows();

    expect(fn () => app(CategoryTree::class)->addNode($outside, Category::factory()->raw()))
        ->toThrow(InvalidArgumentException::class);

    expect(Category::count())->toBe($categoriesBefore)
        ->and(categoryTreeClosureRows())->toEqualCanonicalizing($rowsBefore)
        ->and($rowsBefore)->toBe([[$root->id, $root->id, 0]]);
});

it('TC-89-09: 保存していない親の下には足せず、ノードも閉包表の行も残らない', function () {
    $unsaved = Category::factory()->make();

    expect(fn () => app(CategoryTree::class)->addNode($unsaved, Category::factory()->raw()))
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
    $root = $tree->addNode(null, Category::factory()->raw());
    $mid = $tree->addNode($root, Category::factory()->raw());
    $sibling = $tree->addNode($root, Category::factory()->raw());
    $child = $tree->addNode($mid, Category::factory()->raw());
    $grandchild = $tree->addNode($child, Category::factory()->raw());
    $tree->addNode(null, Category::factory()->raw());

    expect($tree->subtreeIds($mid))->toEqualCanonicalizing([$mid->id, $child->id, $grandchild->id])
        ->and($tree->subtreeIds($mid))->not->toContain($root->id, $sibling->id);
});

it('TC-89-13: 葉の subtreeIds は自分だけ', function () {
    $chain = categoryTreeChain(2);

    expect(app(CategoryTree::class)->subtreeIds($chain[2]))->toBe([$chain[2]->id]);
});

it('TC-89-14: roots は根だけを sort_order の順に返し、sort_order が同じなら ID の順', function () {
    $tree = app(CategoryTree::class);
    $r2 = $tree->addNode(null, Category::factory()->raw(['sort_order' => 2]));
    $r1a = $tree->addNode(null, Category::factory()->raw(['sort_order' => 1]));
    $r1b = $tree->addNode(null, Category::factory()->raw(['sort_order' => 1]));
    $tree->addNode($r1a, Category::factory()->raw(['sort_order' => 0]));

    expect($tree->roots()->pluck('id')->all())->toBe([$r1a->id, $r1b->id, $r2->id]);
});

it('TC-89-15: ノードが 1 つも無いとき roots は空', function () {
    expect(app(CategoryTree::class)->roots()->all())->toBe([]);
});

it('TC-89-16: children はすぐ下の子だけを sort_order の順に返し、sort_order が同じなら ID の順で、孫を含まない', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->raw());
    $c3 = $tree->addNode($root, Category::factory()->raw(['sort_order' => 3]));
    $c1a = $tree->addNode($root, Category::factory()->raw(['sort_order' => 1]));
    $c1b = $tree->addNode($root, Category::factory()->raw(['sort_order' => 1]));
    $tree->addNode($c1a, Category::factory()->raw(['sort_order' => 0]));

    expect($tree->children($root)->pluck('id')->all())->toBe([$c1a->id, $c1b->id, $c3->id]);
});

it('TC-89-17: 葉の children は空', function () {
    $chain = categoryTreeChain(1);

    expect(app(CategoryTree::class)->children($chain[1])->all())->toBe([]);
});

it('TC-21-13: CategoryTree で組んだ木で、Category の parent・children・ancestors・descendants が正しいノードと段数を返す', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->raw());
    $mid = $tree->addNode($root, Category::factory()->raw());
    $sibling = $tree->addNode($root, Category::factory()->raw());
    $leaf = $tree->addNode($mid, Category::factory()->raw());

    $depthById = fn ($relation) => $relation->get()->mapWithKeys(fn (Category $c) => [$c->id => $c->pivot->depth])->sortKeys()->all();

    expect($root->parent)->toBeNull()
        ->and($mid->parent->id)->toBe($root->id)
        ->and($leaf->parent->id)->toBe($mid->id)
        ->and($root->children->pluck('id')->sort()->values()->all())->toBe([$mid->id, $sibling->id])
        ->and($mid->children->pluck('id')->all())->toBe([$leaf->id])
        ->and($leaf->children->all())->toBe([])
        ->and($depthById($leaf->ancestors()))->toBe([$root->id => 2, $mid->id => 1])
        ->and($depthById($root->ancestors()))->toBe([])
        ->and($depthById($root->descendants()))->toBe([$mid->id => 1, $sibling->id => 1, $leaf->id => 2])
        ->and($depthById($leaf->descendants()))->toBe([]);
});
