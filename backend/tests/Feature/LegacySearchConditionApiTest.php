<?php

use App\Http\Requests\ConvertLegacySearchConditionRequest;
use App\Models\Category;
use App\Models\LegacyIdMap;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 旧サイトが保存した検索条件を直す API(POST /api/legacy/search-condition。#290)。直し方は #175 の追記と
// #282 の決まったこと 2(docs/decisions/search-screen.md)。木は CategoryTree::addNode だけで組み、閉包表に行を
// 直接入れない(CLAUDE.md)。旧 ID と新しい ID の対応は、移行(#29・#33)と同じ LegacyIdMap::record で入れる。

uses(RefreshDatabase::class)->group('db');

/**
 * $parent の下(null なら根)にノードを作り、$legacyTable の旧 ID $legacyId との対応を入れる($legacyTable が null なら入れない)。
 */
function legacyConditionNode(?Category $parent, string $name, ?string $legacyTable = null, ?int $legacyId = null): Category
{
    $node = app(CategoryTree::class)->addNode($parent, Category::factory()->make(['name' => $name])->getAttributes());
    if ($legacyTable !== null && $legacyId !== null) {
        LegacyIdMap::record($legacyTable, 'categories', [$legacyId => $node->id]);
    }

    return $node;
}

/**
 * 旧から移した 3 段の木。種別(根)・旧のカテゴリ・旧のミドル。旧 ID はカテゴリとミドルでわざと重ねる(旧でも表ごとに別の連番)。
 *
 * - 洋酒(種別 1)└ ラム(カテゴリ 1)└ ホワイトラム(ミドル 1)/ ダークラム(ミドル 2)/ ゴールドラム(ミドル 3)
 * - 洋酒 └ ジン(カテゴリ 2)└ ドライジン(ミドル 4)/ オールドトム(ミドル 5)
 * - 洋酒 └ テキーラ(カテゴリ 3。子のミドルなし)
 *
 * @return array<string, Category>
 */
function legacyConditionTree(): array
{
    $root = legacyConditionNode(null, '洋酒', 'm_category_kinds', 1);
    $rum = legacyConditionNode($root, 'ラム', 'm_category', 1);
    $gin = legacyConditionNode($root, 'ジン', 'm_category', 2);

    return [
        'root' => $root,
        'rum' => $rum,
        'white' => legacyConditionNode($rum, 'ホワイトラム', 'm_middle', 1),
        'dark' => legacyConditionNode($rum, 'ダークラム', 'm_middle', 2),
        'gold' => legacyConditionNode($rum, 'ゴールドラム', 'm_middle', 3),
        'gin' => $gin,
        'dry' => legacyConditionNode($gin, 'ドライジン', 'm_middle', 4),
        'oldTom' => legacyConditionNode($gin, 'オールドトム', 'm_middle', 5),
        'tequila' => legacyConditionNode($root, 'テキーラ', 'm_category', 3),
    ];
}

/**
 * 旧 ID のまま入れた技法(#33)。旧 ID と同じ値の対応も入れる。
 */
function legacyConditionMethod(int $id): Method
{
    $method = Method::factory()->create(['id' => $id]);
    LegacyIdMap::record('m_method', 'methods', [$id => $id]);

    return $method;
}

const LEGACY_CONDITION_URL = '/api/legacy/search-condition';

it('TC-290-01: カテゴリと、その子のミドルがすべて入っている → カテゴリ 1 つになる', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [1], 'middles' => [1, 2, 3], 'methods' => []])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['rum']->id], 'method_ids' => [], 'dropped_count' => 0]);
});

it('TC-290-02: 子のミドルが一部だけ入っている → 入っているミドルだけになる(カテゴリは送らない)', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [1], 'middles' => [1, 2], 'methods' => []])
        ->assertOk()
        ->assertExactJson([
            'category_ids' => [$tree['white']->id, $tree['dark']->id],
            'method_ids' => [],
            'dropped_count' => 0,
        ]);
});

it('TC-290-03: 子のミドルが 1 つも入っていない → カテゴリ 1 つになる(旧より広くなる)', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [1], 'middles' => [], 'methods' => []])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['rum']->id], 'method_ids' => [], 'dropped_count' => 0]);
});

it('TC-290-04: 子のミドルが無いカテゴリ → カテゴリ 1 つになる', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [3], 'middles' => [], 'methods' => []])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['tequila']->id], 'method_ids' => [], 'dropped_count' => 0]);
});

it('TC-290-05: カテゴリごとに直し方を決め、まとめて昇順で返す(すべて・一部・0 個・ミドルなしの混在)', function () {
    $tree = legacyConditionTree();
    legacyConditionMethod(1);
    legacyConditionMethod(2);

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [3, 2, 1], 'middles' => [5, 1, 3], 'methods' => [2, 1]])
        ->assertOk()
        ->assertExactJson([
            'category_ids' => collect([$tree['white']->id, $tree['gold']->id, $tree['oldTom']->id, $tree['tequila']->id])->sort()->values()->all(),
            'method_ids' => [1, 2],
            'dropped_count' => 0,
        ]);
});

it('TC-290-06: カテゴリとミドルの同じ旧 ID を取り違えない(m_category の 2 と m_middle の 2 は別のノード)', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [2], 'middles' => [4, 5], 'methods' => []])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['gin']->id], 'method_ids' => [], 'dropped_count' => 0]);
});

it('TC-290-07: 対応の無いカテゴリ・ミドル・技法の ID は捨て、捨てた数を返す', function () {
    $tree = legacyConditionTree();
    legacyConditionMethod(1);

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [1, 900], 'middles' => [1, 2, 3, 901, 902], 'methods' => [1, 903]])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['rum']->id], 'method_ids' => [1], 'dropped_count' => 4]);
});

it('TC-290-08: 対応はあるが、移した先のノード・技法がもう無いものは捨てて数える', function () {
    $tree = legacyConditionTree();
    $method = legacyConditionMethod(1);
    // 移行の後に管理画面で消した、という状況。対応(legacy_id_maps)は外部キーが無いので残る
    app(CategoryTree::class)->deleteLeaf($tree['tequila']);
    $method->delete();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [3], 'middles' => [], 'methods' => [1]])
        ->assertOk()
        ->assertExactJson(['category_ids' => [], 'method_ids' => [], 'dropped_count' => 2]);
});

it('TC-290-09: 親のカテゴリが入っていないミドルは捨てて数える', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [2], 'middles' => [1, 4], 'methods' => []])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['dry']->id], 'method_ids' => [], 'dropped_count' => 1]);
});

it('TC-290-10: 技法の ID は振り直さず、そのまま返す', function () {
    legacyConditionMethod(3);
    legacyConditionMethod(6);

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [], 'middles' => [], 'methods' => [6, 3]])
        ->assertOk()
        ->assertExactJson(['category_ids' => [], 'method_ids' => [3, 6], 'dropped_count' => 0]);
});

it('TC-290-11: 重なった ID は 1 つにまとめ、捨てた数に数えない(旧の「リセット」で技法が重なった保存値)', function () {
    $tree = legacyConditionTree();
    legacyConditionMethod(1);

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [1, 1], 'middles' => [1, 1], 'methods' => [1, 1, 1]])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['white']->id], 'method_ids' => [1], 'dropped_count' => 0]);
});

it('TC-290-12: 空の入力・キーを省いた入力は、空の条件で捨てた数 0', /** @param array<string, mixed> $body */ function (array $body) {
    legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, $body)
        ->assertOk()
        ->assertExactJson(['category_ids' => [], 'method_ids' => [], 'dropped_count' => 0]);
})->with([
    '空の配列' => [['categories' => [], 'middles' => [], 'methods' => []]],
    'キーなし' => [[]],
]);

it('TC-290-13: 移行の後に足した子があっても、旧のミドルがすべて入っていればカテゴリ 1 つになる', function () {
    $tree = legacyConditionTree();
    legacyConditionNode($tree['rum'], 'スパイスドラム');

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => [1], 'middles' => [1, 2, 3], 'methods' => []])
        ->assertOk()
        ->assertExactJson(['category_ids' => [$tree['rum']->id], 'method_ids' => [], 'dropped_count' => 0]);
});

it('TC-290-14: 形の合わない入力は 422', /** @param array<string, mixed> $body */ function (array $body, string $errorKey) {
    $this->postJson(LEGACY_CONDITION_URL, $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);
})->with([
    '配列でない' => [['categories' => 1], 'categories'],
    '整数でない' => [['middles' => ['a']], 'middles.0'],
    '小数' => [['methods' => [1.5]], 'methods.0'],
    'null の要素' => [['categories' => [null]], 'categories.0'],
    'キーつきの配列' => [['middles' => ['x' => 1]], 'middles'],
    '上限を超える数' => [['categories' => range(1, ConvertLegacySearchConditionRequest::MAX_IDS + 1)], 'categories'],
]);

it('TC-290-15: 上限ちょうどの数の ID は受ける', function () {
    $tree = legacyConditionTree();

    $this->postJson(LEGACY_CONDITION_URL, ['categories' => range(1, ConvertLegacySearchConditionRequest::MAX_IDS)])
        ->assertOk()
        ->assertJson(['dropped_count' => ConvertLegacySearchConditionRequest::MAX_IDS - 3])
        ->assertJsonPath('category_ids', collect([$tree['rum']->id, $tree['gin']->id, $tree['tequila']->id])->sort()->values()->all());
});

it('TC-290-16: GET では受けない(405)', function () {
    $this->getJson(LEGACY_CONDITION_URL.'?categories[]=1')->assertStatus(405);
});
