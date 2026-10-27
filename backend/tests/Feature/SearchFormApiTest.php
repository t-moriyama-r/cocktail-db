<?php

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// 検索フォーム用の木の API(GET /api/search-form。#95)。返す深さは docs/schema.md の「検索フォーム(#95)に出す深さ」
// (すべての段)。木は CategoryTree::addNode・moveSubtree だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * $parent の下(null なら根)にノードを作る。
 *
 * @param  array<string, mixed>  $attrs
 */
function searchFormNode(?Category $parent, string $name, array $attrs = []): Category
{
    return app(CategoryTree::class)->addNode(
        $parent,
        Category::factory()->make(['name' => $name, ...$attrs])->getAttributes(),
    );
}

/**
 * 応答で期待するノード 1 つ。
 *
 * @param  list<array<string, mixed>>  $children
 * @return array<string, mixed>
 */
function searchFormExpected(Category $node, ?string $rootRole, array $children = [], bool $isMajor = false): array
{
    return [
        'id' => $node->id,
        'name' => $node->name,
        'is_major' => $isMajor,
        'root_role' => $rootRole,
        'children' => $children,
    ];
}

it('TC-95-01: 根から深さ 5 までの木が、ノードごとに ID・名前・メジャーか・根の役割・子の入れ子で返る', function () {
    $root = searchFormNode(null, '蒸留酒');
    $chain = [$root];
    for ($depth = 1; $depth <= 5; $depth++) {
        $chain[] = searchFormNode($chain[$depth - 1], "d{$depth}");
    }

    $expected = [];
    for ($depth = 5; $depth >= 0; $depth--) {
        $expected = searchFormExpected($chain[$depth], null, $depth === 5 ? [] : [$expected]);
    }

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson(['categories' => [$expected], 'methods' => []]);
});

it('TC-95-02: 根も兄弟も、並び順(sort_order)の昇順で、同じなら ID の昇順で返る', function () {
    $later = searchFormNode(null, '後の根', ['sort_order' => 2]);
    $earlier = searchFormNode(null, '先の根', ['sort_order' => 1]);
    $b = searchFormNode($earlier, 'b', ['sort_order' => 5]);
    $c = searchFormNode($earlier, 'c', ['sort_order' => 3]);
    $d = searchFormNode($earlier, 'd', ['sort_order' => 3]);

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson([
            'categories' => [
                searchFormExpected($earlier, null, [
                    searchFormExpected($c, null),
                    searchFormExpected($d, null),
                    searchFormExpected($b, null),
                ]),
                searchFormExpected($later, null),
            ],
            'methods' => [],
        ]);
});

it('TC-95-03: どの深さのノードにも、そのノードの根の役割(soft_drink・other・役割なしは null)が入る', function (?CategoryRole $role) {
    $root = searchFormNode(null, '根', ['role' => $role]);
    $child = searchFormNode($root, '子');
    $grandchild = searchFormNode($child, '孫');

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson([
            'categories' => [
                searchFormExpected($root, $role?->value, [
                    searchFormExpected($child, $role?->value, [
                        searchFormExpected($grandchild, $role?->value),
                    ]),
                ]),
            ],
            'methods' => [],
        ]);
})->with([
    'ソフトドリンク' => [CategoryRole::SoftDrink],
    'その他' => [CategoryRole::Other],
    '役割なし' => [null],
]);

it('TC-95-04: メジャーか(is_major)は、ノードごとに保存した値で返る', function () {
    $root = searchFormNode(null, '根');
    $major = searchFormNode($root, 'メジャー', ['is_major' => true]);
    $minor = searchFormNode($root, 'メジャーでない', ['is_major' => false]);
    $majorUnderMinor = searchFormNode($minor, 'メジャーでない親の下のメジャー', ['is_major' => true]);

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson([
            'categories' => [
                searchFormExpected($root, null, [
                    searchFormExpected($major, null, isMajor: true),
                    searchFormExpected($minor, null, [
                        searchFormExpected($majorUnderMinor, null, isMajor: true),
                    ]),
                ]),
            ],
            'methods' => [],
        ]);
});

it('TC-95-05: 下書きのカクテルでしか使っていないノードも、どのカクテルにも使っていないノードも、ほかのノードと同じに返る', function () {
    $root = searchFormNode(null, '根');
    $usedByPublished = searchFormNode($root, '公開のカクテルで使う');
    $usedByDraftOnly = searchFormNode($root, '下書きでしか使わない');
    $unused = searchFormNode($root, 'どこにも使わない');

    $published = Cocktail::factory()->create(['base_category_id' => $usedByPublished->id, 'is_draft' => false]);
    $draft = Cocktail::factory()->create(['base_category_id' => $usedByDraftOnly->id, 'is_draft' => true]);
    CocktailIngredient::factory()->create(['cocktail_id' => $published->id, 'category_id' => $usedByPublished->id, 'position' => 1]);
    CocktailIngredient::factory()->create(['cocktail_id' => $draft->id, 'category_id' => $usedByDraftOnly->id, 'position' => 1]);

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson([
            'categories' => [
                searchFormExpected($root, null, [
                    searchFormExpected($usedByPublished, null),
                    searchFormExpected($usedByDraftOnly, null),
                    searchFormExpected($unused, null),
                ]),
            ],
            'methods' => [],
        ]);
});

it('TC-95-06: 技法の一覧が、ID の昇順で ID・名前を持って返る', function () {
    DB::table('methods')->insert([
        ['id' => 6, 'name' => 'ブレンド', 'name_eng' => 'Blend', 'description' => ''],
        ['id' => 1, 'name' => 'ビルド', 'name_eng' => 'Build', 'description' => '説明'],
        ['id' => 3, 'name' => 'シェイク', 'name_eng' => 'Shake', 'description' => ''],
    ]);

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson([
            'categories' => [],
            'methods' => [
                ['id' => 1, 'name' => 'ビルド'],
                ['id' => 3, 'name' => 'シェイク'],
                ['id' => 6, 'name' => 'ブレンド'],
            ],
        ]);
});

it('TC-95-07: ノードも技法も無いときは、どちらも空の配列で返る', function () {
    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson(['categories' => [], 'methods' => []]);
});

it('TC-95-08: 部分木を別の根の下へ移すと、移した先の位置に、移した先の根の役割で返る', function () {
    $liquor = searchFormNode(null, '蒸留酒', ['sort_order' => 1]);
    $other = searchFormNode(null, 'その他', ['role' => CategoryRole::Other, 'sort_order' => 2]);
    $moved = searchFormNode($liquor, '移す');
    $movedChild = searchFormNode($moved, '移す子');

    app(CategoryTree::class)->moveSubtree($moved, $other);

    $this->getJson('/api/search-form')
        ->assertOk()
        ->assertExactJson([
            'categories' => [
                searchFormExpected($liquor, null),
                searchFormExpected($other, 'other', [
                    searchFormExpected($moved, 'other', [
                        searchFormExpected($movedChild, 'other'),
                    ]),
                ]),
            ],
            'methods' => [],
        ]);
});

it('TC-95-09: ログインしていなくても呼べ、セッションのクッキーを付けない', function () {
    $response = $this->getJson('/api/search-form')->assertOk();

    expect($response->getCookie(config()->string('session.cookie')))->toBeNull();
});
