<?php

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;

// CategoryTree の根の役割と、役割による判定(#155。決まったことは docs/decisions/category-root-role.md)を、
// MariaDB の閉包表で確かめる。木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * 役割の違う 3 つの根の下に、深さ 5 までの鎖を 1 本ずつ作る。
 *
 * 蒸留酒(根。null) ─ d1 ─ d2 ─ d3 ─ d4 ─ d5
 * ソフトドリンク(根。soft_drink) ─ d1 ─ … ─ d5
 * その他(根。other) ─ d1 ─ … ─ d5
 *
 * @return array<string, list<Category>> 根の名前 → 根から順のノード(6 個)
 */
function rootRoleTree(): array
{
    $tree = app(CategoryTree::class);
    $chains = [];
    foreach (['蒸留酒' => null, 'ソフトドリンク' => CategoryRole::SoftDrink, 'その他' => CategoryRole::Other] as $name => $role) {
        $chain = [$tree->addNode(null, rootRoleAttrs(['name' => $name, 'role' => $role]))];
        for ($depth = 1; $depth <= 5; $depth++) {
            $chain[] = $tree->addNode($chain[$depth - 1], rootRoleAttrs(['name' => "{$name}-d{$depth}"]));
        }
        $chains[$name] = $chain;
    }

    return $chains;
}

/**
 * addNode に渡す、保存していないノードの属性。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rootRoleAttrs(array $overrides = []): array
{
    return Category::factory()->make($overrides)->getAttributes();
}

/**
 * ノードについて、根の役割と 3 つの判定をまとめて返す。
 *
 * @return array{?CategoryRole, bool, bool, bool} [rootRole, isOther, isFamousBrandOptional, isNonLiquor]
 */
function rootRoleJudgements(Category $node): array
{
    $tree = app(CategoryTree::class);

    return [$tree->rootRole($node), $tree->isOther($node), $tree->isFamousBrandOptional($node), $tree->isNonLiquor($node)];
}

/**
 * otherNodeIds のサブクエリが返すノードの名前を、昇順で返す。
 *
 * @return list<string>
 */
function rootRoleOtherNodeNames(): array
{
    return array_values(Category::query()
        ->whereIn('id', app(CategoryTree::class)->otherNodeIds())
        ->orderBy('name')
        ->get()
        ->map(fn (Category $node): string => $node->name)
        ->all());
}

/**
 * isOther が true になるノードの名前を、すべてのノードを 1 つずつ判定して昇順で返す。
 *
 * @return list<string>
 */
function rootRoleOtherNodeNamesOneByOne(): array
{
    $tree = app(CategoryTree::class);

    return array_values(Category::query()
        ->orderBy('name')
        ->get()
        ->filter(fn (Category $node): bool => $tree->isOther($node))
        ->map(fn (Category $node): string => $node->name)
        ->all());
}

dataset('root role partitions', [
    '蒸留酒(null)' => ['蒸留酒', [null, false, false, false]],
    'ソフトドリンク(soft_drink)' => ['ソフトドリンク', [CategoryRole::SoftDrink, false, true, true]],
    'その他(other)' => ['その他', [CategoryRole::Other, true, true, true]],
]);

it('TC-155-01: 根自身の判定は、その根の役割で決まる', function (string $root, array $expected) {
    $chains = rootRoleTree();

    expect(rootRoleJudgements($chains[$root][0]))->toBe($expected);
})->with('root role partitions');

it('TC-155-02: 根の直下(深さ 1)のノードの判定は、根の役割で決まる', function (string $root, array $expected) {
    $chains = rootRoleTree();

    expect(rootRoleJudgements($chains[$root][1]))->toBe($expected);
})->with('root role partitions');

it('TC-155-03: 深さ 5 のノードの判定は、根の役割で決まる', function (string $root, array $expected) {
    $chains = rootRoleTree();

    expect(rootRoleJudgements($chains[$root][5]))->toBe($expected);
})->with('root role partitions');

it('TC-155-04: 役割の無い根の部分木を「その他」の根の下へ移すと、部分木のすべてのノードの判定が「その他」になる', function () {
    $chains = rootRoleTree();
    $tree = app(CategoryTree::class);
    $moved = array_slice($chains['蒸留酒'], 2);

    $tree->moveSubtree($chains['蒸留酒'][2], $chains['その他'][1]);

    foreach ($moved as $node) {
        expect(rootRoleJudgements($node))->toBe([CategoryRole::Other, true, true, true]);
    }
    // 移さなかった上の段は、元の根の役割のまま
    expect(rootRoleJudgements($chains['蒸留酒'][1]))->toBe([null, false, false, false]);
});

it('TC-155-05: 「その他」の部分木を、ソフトドリンクの根の下・役割の無い根の下・根の位置へ移すと、判定が移した先の根の役割になる', function () {
    $chains = rootRoleTree();
    $tree = app(CategoryTree::class);
    $node = $chains['その他'][3];
    $leaf = $chains['その他'][5];

    $tree->moveSubtree($node, $chains['ソフトドリンク'][0]);
    expect(rootRoleJudgements($leaf))->toBe([CategoryRole::SoftDrink, false, true, true]);

    $tree->moveSubtree($node, $chains['蒸留酒'][4]);
    expect(rootRoleJudgements($leaf))->toBe([null, false, false, false]);

    // 根の位置へ移すと、役割を持たない新しい根になる
    $tree->moveSubtree($node, null);
    expect(rootRoleJudgements($node))->toBe([null, false, false, false])
        ->and(rootRoleJudgements($leaf))->toBe([null, false, false, false]);
});

it('TC-155-06: otherNodeIds が返すノードは、すべてのノードを isOther で 1 つずつ判定した結果と一致する(移す前・移した後)', function () {
    $chains = rootRoleTree();
    $tree = app(CategoryTree::class);

    expect(rootRoleOtherNodeNames())->toBe(rootRoleOtherNodeNamesOneByOne())
        ->and(rootRoleOtherNodeNames())->toHaveCount(6);

    $tree->moveSubtree($chains['蒸留酒'][3], $chains['その他'][0]);
    $tree->moveSubtree($chains['その他'][4], $chains['ソフトドリンク'][2]);

    // その他の 6 個 - 移した 2 個(d4・d5) + 移してきた 3 個(蒸留酒の d3〜d5) = 7 個
    expect(rootRoleOtherNodeNames())->toBe(rootRoleOtherNodeNamesOneByOne())
        ->and(rootRoleOtherNodeNames())->toHaveCount(7);
});

it('TC-155-07: isRoot は根だけで true になり、根の直下・深さ 5 では false になる。根の部分木を別のノードの下へ移すと根でなくなる', function () {
    $chains = rootRoleTree();
    $tree = app(CategoryTree::class);

    expect($tree->isRoot($chains['蒸留酒'][0]))->toBeTrue()
        ->and($tree->isRoot($chains['その他'][0]))->toBeTrue()
        ->and($tree->isRoot($chains['蒸留酒'][1]))->toBeFalse()
        ->and($tree->isRoot($chains['蒸留酒'][5]))->toBeFalse();

    $tree->moveSubtree($chains['蒸留酒'][2], null);
    expect($tree->isRoot($chains['蒸留酒'][2]))->toBeTrue();

    $tree->moveSubtree($chains['蒸留酒'][2], $chains['蒸留酒'][1]);
    expect($tree->isRoot($chains['蒸留酒'][2]))->toBeFalse();
});

it('TC-155-08: 木に入っていないノード・保存していないノードでは、根の役割と判定を引かずに例外にする', function (string $method) {
    $tree = app(CategoryTree::class);
    $outside = Category::factory()->create(['role' => CategoryRole::Other]);
    $unsaved = Category::factory()->make(['role' => CategoryRole::Other]);

    expect(fn () => $tree->{$method}($outside))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $tree->{$method}($unsaved))->toThrow(InvalidArgumentException::class);
})->with(['rootRole', 'isOther', 'isFamousBrandOptional', 'isNonLiquor', 'isRoot']);

it('TC-155-09: 親のあるノードを役割つきで作ろうとすると例外になり、ノードも閉包表の行も増えない', function (CategoryRole|string $role) {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, rootRoleAttrs());
    $categories = Category::query()->count();
    $closures = CategoryClosure::query()->count();

    expect(fn () => $tree->addNode($root, rootRoleAttrs(['role' => $role])))->toThrow(InvalidArgumentException::class)
        ->and(Category::query()->count())->toBe($categories)
        ->and(CategoryClosure::query()->count())->toBe($closures);
})->with([
    'enum で渡す' => [CategoryRole::Other],
    '文字列で渡す' => ['soft_drink'],
]);

it('TC-155-10: 役割を持つ根は、ほかのノードの下へ移せず、閉包表は変わらない', function () {
    $chains = rootRoleTree();
    $tree = app(CategoryTree::class);
    $before = CategoryClosure::query()->orderBy('ancestor_id')->orderBy('descendant_id')->get(['ancestor_id', 'descendant_id', 'depth'])->toArray();

    expect(fn () => $tree->moveSubtree($chains['その他'][0], $chains['蒸留酒'][0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $tree->moveSubtree($chains['ソフトドリンク'][0], $chains['蒸留酒'][3]))->toThrow(InvalidArgumentException::class)
        ->and(CategoryClosure::query()->orderBy('ancestor_id')->orderBy('descendant_id')->get(['ancestor_id', 'descendant_id', 'depth'])->toArray())
        ->toBe($before);
});

it('TC-155-11: 役割は保存すると CategoryRole で読める。役割の無いノードは null', function () {
    $tree = app(CategoryTree::class);
    $other = $tree->addNode(null, rootRoleAttrs(['role' => 'other']));
    $plain = $tree->addNode($other, rootRoleAttrs());

    expect(Category::query()->findOrFail($other->id)->role)->toBe(CategoryRole::Other)
        ->and(Category::query()->findOrFail($plain->id)->role)->toBeNull();
});
