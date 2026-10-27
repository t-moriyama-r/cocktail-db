<?php

use App\Models\Category;
use App\Rules\NotRootCategory;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

// 材料・ベースに根を使わせない検証の部品(#155。docs/decisions/category-root-role.md の 4)を、MariaDB の閉包表で確かめる。

uses(RefreshDatabase::class)->group('db');

/**
 * category_id を NotRootCategory で検証し、エラーの数を返す(通れば 0)。
 */
function notRootCategoryErrors(mixed $value): int
{
    $validator = Validator::make(
        ['category_id' => $value],
        ['category_id' => [app(NotRootCategory::class)]],
    );

    return count($validator->errors()->get('category_id'));
}

it('TC-155-12: 根の ID は通らず、根の直下・深さ 5 のノードの ID は通る', function () {
    $tree = app(CategoryTree::class);
    $root = $tree->addNode(null, Category::factory()->make(['role' => 'other'])->getAttributes());
    $nodes = [$root];
    for ($depth = 1; $depth <= 5; $depth++) {
        $nodes[] = $tree->addNode($nodes[$depth - 1], Category::factory()->make()->getAttributes());
    }

    expect(notRootCategoryErrors($root->id))->toBe(1)
        ->and(notRootCategoryErrors((string) $root->id))->toBe(1)
        ->and(notRootCategoryErrors($nodes[1]->id))->toBe(0)
        ->and(notRootCategoryErrors((string) $nodes[5]->id))->toBe(0);
});

it('TC-155-13: 無い ID・ID の形でない値では何も言わない(ほかの規則の役目)', function (mixed $value) {
    expect(notRootCategoryErrors($value))->toBe(0);
})->with([
    '無い ID' => [999999],
    '数字でない文字列' => ['abc'],
    '負の数の文字列' => ['-1'],
    '配列' => [[1]],
]);
