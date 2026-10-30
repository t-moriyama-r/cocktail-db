<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// 別のカクテルの API(GET /api/cocktails/{id}/related・GET /api/cocktails/random。#174)。
// 何が当たるかは docs/decisions/related-cocktails.md。期待値は旧 CocktailFactory::RandomByGetRandom・getCocktailIds・
// RandomByCocktail の選び方と、そこに書いた旧との差分から置く。木は CategoryTree::addNode だけで組む(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木。
 *
 * 洋酒(根)
 * ├ ラム ─────────── 銘柄: ラムの銘柄
 * │  ├ ホワイトラム ── 銘柄: バカルディ・別のホワイトラム
 * │  │  └ 熟成ホワイト
 * │  └ ダークラム
 * └ ジン ─────────── 銘柄: ゴードン
 *    └ ドライジン
 * ソフトドリンク(根)
 * ├ コーラ
 * └ ソーダ
 * その他(根)
 * └ ライム
 *
 * @return array<string, Category|Brand>
 */
function relatedTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['熟成ホワイト'] = $add($n['ホワイトラム'], '熟成ホワイト');
    $n['ダークラム'] = $add($n['ラム'], 'ダークラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ドライジン'] = $add($n['ジン'], 'ドライジン');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク');
    $n['コーラ'] = $add($n['ソフトドリンク'], 'コーラ');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ');
    $n['その他'] = $add(null, 'その他');
    $n['ライム'] = $add($n['その他'], 'ライム');
    $n['バカルディ'] = Brand::factory()->create(['category_id' => $n['ホワイトラム']->id, 'name' => 'バカルディ']);
    $n['別のホワイトラム'] = Brand::factory()->create(['category_id' => $n['ホワイトラム']->id, 'name' => '別のホワイトラム']);
    $n['ラムの銘柄'] = Brand::factory()->create(['category_id' => $n['ラム']->id, 'name' => 'ラムの銘柄']);
    $n['ゴードン'] = Brand::factory()->create(['category_id' => $n['ジン']->id, 'name' => 'ゴードン']);

    return $n;
}

/**
 * 材料を並び順に並べてカクテルを作る。材料はノードか銘柄(銘柄つきの材料)。[材料, true] なら任意の材料。
 *
 * @param  list<Category|Brand|array{Category|Brand, bool}>  $ingredients
 * @param  array<string, mixed>  $attrs
 */
function relatedCocktail(array $ingredients, array $attrs = []): Cocktail
{
    $cocktail = Cocktail::factory()->create($attrs);
    foreach ($ingredients as $position => $ingredient) {
        [$item, $isOptional] = is_array($ingredient) ? $ingredient : [$ingredient, false];
        $factory = CocktailIngredient::factory();
        $factory = $item instanceof Brand
            ? $factory->forBrand($item)
            : $factory->state(['category_id' => $item->id]);
        $factory->create(['cocktail_id' => $cocktail->id, 'position' => $position + 1, 'is_optional' => $isOptional]);
    }

    return $cocktail;
}

/**
 * 応答のカクテルの ID を、返った順で。
 *
 * @param  TestResponse<Response>  $response
 * @return list<int>
 */
function relatedOrder(TestResponse $response): array
{
    $ids = [];
    foreach ((array) $response->assertOk()->json('data.*.id') as $id) {
        $ids[] = is_int($id) ? $id : throw new UnexpectedValueException('カクテルの ID が整数でない');
    }

    return $ids;
}

/**
 * 応答のカクテルの ID を昇順で。返る順はランダムなので、集合として比べる。
 *
 * @param  TestResponse<Response>  $response
 * @return list<int>
 */
function relatedIds(TestResponse $response): array
{
    $ids = relatedOrder($response);
    sort($ids);

    return $ids;
}

/**
 * カクテルの ID を昇順で。
 *
 * @param  list<Cocktail>  $cocktails
 * @return list<int>
 */
function relatedExpected(array $cocktails): array
{
    $ids = array_map(fn (Cocktail $c): int => $c->id, $cocktails);
    sort($ids);

    return $ids;
}

it('TC-174-01: ノードを起点にすると、そのノードと配下を材料に使うカクテルを返す(ほかのノードは返さない)', function () {
    $n = relatedTree();
    $rum = relatedCocktail([$n['ラム']]);
    $white = relatedCocktail([$n['ホワイトラム'], $n['コーラ']]);
    $dark = relatedCocktail([$n['ダークラム']]);
    $aged = relatedCocktail([$n['熟成ホワイト']]);
    relatedCocktail([$n['ジン'], $n['コーラ']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?category_id={$n['ラム']->id}&limit=50")))
        ->toBe(relatedExpected([$rum, $white, $dark, $aged]));
});

it('TC-174-02: ノードを起点にすると、より大まかなノード(祖先)だけを指定した材料のカクテルも返す', function () {
    $n = relatedTree();
    $rum = relatedCocktail([$n['ラム']]);
    $white = relatedCocktail([$n['ホワイトラム']]);
    $aged = relatedCocktail([$n['熟成ホワイト']]);
    relatedCocktail([$n['ダークラム']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?category_id={$n['ホワイトラム']->id}&limit=50")))
        ->toBe(relatedExpected([$rum, $white, $aged]));
});

it('TC-174-03: ノードを起点にしても、根を材料に使うカクテルは祖先として当てない', function () {
    $n = relatedTree();
    // 根は材料に使わない(#153)が、使っていても祖先の向きでは根まで広げない
    relatedCocktail([$n['洋酒']]);
    $white = relatedCocktail([$n['ホワイトラム']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?category_id={$n['ホワイトラム']->id}&limit=50")))
        ->toBe(relatedExpected([$white]));
});

it('TC-174-04: 銘柄を起点にすると、その銘柄か銘柄を指定していない、銘柄のノードと祖先の材料のカクテルを返す', function () {
    $n = relatedTree();
    $bacardi = relatedCocktail([$n['バカルディ']]);
    $white = relatedCocktail([$n['ホワイトラム']]);
    $rum = relatedCocktail([$n['ラム']]);
    relatedCocktail([$n['別のホワイトラム']]);
    relatedCocktail([$n['ラムの銘柄']]);
    relatedCocktail([$n['熟成ホワイト']]);
    relatedCocktail([$n['ダークラム']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?brand_id={$n['バカルディ']->id}&limit=50")))
        ->toBe(relatedExpected([$bacardi, $white, $rum]));
});

it('TC-174-05: 子のあるノードの銘柄を起点にすると、配下のノードの材料のカクテルは返さない', function () {
    // 旧の「ミドルの無い銘柄」と同じ形。旧 getCocktailIds はミドルが null の材料だけに絞っていた
    $n = relatedTree();
    $gordon = relatedCocktail([$n['ゴードン']]);
    $gin = relatedCocktail([$n['ジン']]);
    relatedCocktail([$n['ドライジン']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?brand_id={$n['ゴードン']->id}&limit=50")))
        ->toBe(relatedExpected([$gordon, $gin]));
});

it('TC-174-06: ベースを起点にすると、ベースがそのノードのカクテルだけを返す(配下のベースは返さない)', function () {
    $n = relatedTree();
    $rumBase = relatedCocktail([$n['ライム']], ['base_category_id' => $n['ラム']->id]);
    relatedCocktail([$n['ライム']], ['base_category_id' => $n['ホワイトラム']->id]);
    relatedCocktail([$n['ラム']], ['base_category_id' => $n['ジン']->id]);

    expect(relatedIds($this->getJson("/api/cocktails/random?base_id={$n['ラム']->id}&limit=50")))
        ->toBe(relatedExpected([$rumBase]));
});

it('TC-174-07: 技法を起点にすると、その技法のカクテルだけを返す', function () {
    $n = relatedTree();
    $shake = Method::factory()->create();
    $shaken = relatedCocktail([$n['ラム']], ['method_id' => $shake->id]);
    relatedCocktail([$n['ラム']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?method_id={$shake->id}&limit=50")))
        ->toBe(relatedExpected([$shaken]));
});

it('TC-174-08: グラスを起点にすると、そのグラスのカクテルだけを返す', function () {
    $n = relatedTree();
    $glass = Dictionary::factory()->create();
    $inGlass = relatedCocktail([$n['ラム']], ['glass_dictionary_id' => $glass->id]);
    relatedCocktail([$n['ラム']], ['glass_dictionary_id' => Dictionary::factory()->create()->id]);
    relatedCocktail([$n['ラム']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?glass_id={$glass->id}&limit=50")))
        ->toBe(relatedExpected([$inGlass]));
});

it('TC-174-09: カクテルを起点にすると、最初の 2 つの材料のどちらかを使うカクテル(自分は除く)から返す', function () {
    $n = relatedTree();
    $target = relatedCocktail([$n['ジン'], $n['コーラ'], $n['ライム']]);
    $gin = relatedCocktail([$n['ドライジン']]);
    $cola = relatedCocktail([$n['コーラ'], $n['ソーダ']]);
    relatedCocktail([$n['ライム']]);

    // 最初の 2 つで 2 件そろうので、3 つめのライムは足さない
    expect(relatedIds($this->getJson("/api/cocktails/{$target->id}/related?limit=2")))
        ->toBe(relatedExpected([$gin, $cola]));
});

it('TC-174-10: カクテルを起点にして足りなければ、材料を順に足し、limit 件に届いたところで止める', function (int $limit, array $expected) {
    /** @var list<string> $expected */
    $n = relatedTree();
    $c = [];
    $c['起点'] = relatedCocktail([$n['ジン'], $n['コーラ'], $n['ライム'], $n['ソーダ']]);
    $c['ジン'] = relatedCocktail([$n['ジン']]);
    $c['ライム'] = relatedCocktail([$n['ライム']]);
    $c['ソーダ'] = relatedCocktail([$n['ソーダ']]);

    expect(relatedIds($this->getJson("/api/cocktails/{$c['起点']->id}/related?limit={$limit}")))
        ->toBe(relatedExpected(array_map(fn (string $name): Cocktail => $c[$name], $expected)));
})->with([
    '2 つで足りる(1 件)' => [1, ['ジン']],
    '3 つめを足す(2 件)' => [2, ['ジン', 'ライム']],
    '4 つめまで足す(3 件)' => [3, ['ジン', 'ライム', 'ソーダ']],
    '全部足しても足りない(50 件)' => [50, ['ジン', 'ライム', 'ソーダ']],
]);

it('TC-174-11: カクテルを起点にすると、銘柄つきの材料は、その銘柄か銘柄を指定していない材料にだけ一致させる', function () {
    $n = relatedTree();
    $target = relatedCocktail([$n['バカルディ'], $n['ライム']]);
    $white = relatedCocktail([$n['ホワイトラム']]);
    relatedCocktail([$n['別のホワイトラム']]);
    relatedCocktail([$n['熟成ホワイト']]);

    expect(relatedIds($this->getJson("/api/cocktails/{$target->id}/related?limit=50")))
        ->toBe(relatedExpected([$white]));
});

it('TC-174-12: 任意の材料も、一致する材料として扱い、カクテルを起点にするときの最初の 2 つにも数える', function () {
    $n = relatedTree();
    $optionalRum = relatedCocktail([$n['ライム'], [$n['ラム'], true]]);
    $target = relatedCocktail([[$n['ジン'], true], $n['ソーダ'], $n['コーラ']]);
    $gin = relatedCocktail([$n['ジン']]);

    expect(relatedIds($this->getJson("/api/cocktails/random?category_id={$n['ラム']->id}&limit=50")))
        ->toBe(relatedExpected([$optionalRum]))
        ->and(relatedIds($this->getJson("/api/cocktails/{$target->id}/related?limit=2")))
        ->toBe(relatedExpected([$gin]));
});

it('TC-174-13: 下書きのカクテルは、どの起点でも返さない', function (string $origin) {
    $n = relatedTree();
    $method = Method::factory()->create();
    $glass = Dictionary::factory()->create();
    $attrs = ['base_category_id' => $n['ラム']->id, 'method_id' => $method->id, 'glass_dictionary_id' => $glass->id];
    $published = relatedCocktail([$n['バカルディ']], $attrs);
    relatedCocktail([$n['バカルディ']], [...$attrs, 'is_draft' => true]);

    $query = match ($origin) {
        'category_id' => "category_id={$n['ホワイトラム']->id}",
        'brand_id' => "brand_id={$n['バカルディ']->id}",
        'base_id' => "base_id={$n['ラム']->id}",
        'method_id' => "method_id={$method->id}",
        'glass_id' => "glass_id={$glass->id}",
        default => null,
    };
    // カクテルを起点にするときは、起点のカクテル自身は返らない(決まったこと 6)ので、同じ材料の別の公開のカクテルを起点にする
    $url = $query === null
        ? '/api/cocktails/'.relatedCocktail([$n['バカルディ']])->id.'/related?limit=50'
        : "/api/cocktails/random?{$query}&limit=50";

    expect(relatedIds($this->getJson($url)))->toBe(relatedExpected([$published]));
})->with(['category_id', 'brand_id', 'base_id', 'method_id', 'glass_id', 'cocktail']);

it('TC-174-14: limit より多く当たるときは limit 件に絞り、当たるものの中から返す', function () {
    $n = relatedTree();
    $all = [relatedCocktail([$n['ラム']]), relatedCocktail([$n['ラム']]), relatedCocktail([$n['ラム']])];

    $ids = relatedIds($this->getJson("/api/cocktails/random?category_id={$n['ラム']->id}&limit=2"));

    expect($ids)->toHaveCount(2)
        ->and(array_diff($ids, relatedExpected($all)))->toBe([]);
});

it('TC-174-15: 返す順はランダム(同じ条件で何度か呼ぶと、違う順が出る)', function () {
    $n = relatedTree();
    foreach (range(1, 5) as $_) {
        relatedCocktail([$n['ラム']]);
    }

    // 5 件の並びは 120 通り。30 回すべて同じ順になる確率は 120^-29 で、無視できる
    $orders = collect(range(1, 30))
        ->map(fn (): string => implode(',', relatedOrder($this->getJson("/api/cocktails/random?category_id={$n['ラム']->id}&limit=5"))))
        ->unique();

    expect($orders->count())->toBeGreaterThan(1);
});

it('TC-174-16: limit は 1〜50 の整数だけを受ける', function (string $limit, int $status) {
    $n = relatedTree();
    $target = relatedCocktail([$n['ラム']]);

    $this->getJson("/api/cocktails/random?category_id={$n['ラム']->id}&limit={$limit}")->assertStatus($status);
    $this->getJson("/api/cocktails/{$target->id}/related?limit={$limit}")->assertStatus($status);
})->with([
    '1' => ['1', 200],
    '50' => ['50', 200],
    '51' => ['51', 422],
    '0' => ['0', 422],
    '空' => ['', 422],
    '小数' => ['1.5', 422],
    '数字でない' => ['abc', 422],
]);

it('TC-174-17: limit を省くと 422', function () {
    $n = relatedTree();
    $target = relatedCocktail([$n['ラム']]);

    $this->getJson("/api/cocktails/random?category_id={$n['ラム']->id}")->assertUnprocessable()->assertJsonValidationErrors('limit');
    $this->getJson("/api/cocktails/{$target->id}/related")->assertUnprocessable()->assertJsonValidationErrors('limit');
});

it('TC-174-18: 起点が無い・2 つ以上あるときは 422', function (string $query) {
    relatedTree();

    $this->getJson("/api/cocktails/random?limit=5{$query}")->assertUnprocessable();
})->with([
    '無い' => [''],
    'ノードとベース' => ['&category_id=1&base_id=1'],
    '技法とグラス' => ['&method_id=1&glass_id=1'],
]);

it('TC-174-19: 無いノード・銘柄・ベース・グラスを起点にすると 0 件、無い技法は 422', function (string $origin, int $status) {
    $n = relatedTree();
    relatedCocktail([$n['ラム']]);

    $response = $this->getJson("/api/cocktails/random?{$origin}=999999&limit=5")->assertStatus($status);
    if ($status === 200) {
        $response->assertExactJson(['data' => []]);
    }
})->with([
    'ノード' => ['category_id', 200],
    '銘柄' => ['brand_id', 200],
    'ベース' => ['base_id', 200],
    'グラス' => ['glass_id', 200],
    '技法' => ['method_id', 422],
]);

it('TC-174-20: 起点のカクテルが下書き・無い ID なら 404', function () {
    $n = relatedTree();
    $draft = relatedCocktail([$n['ラム']], ['is_draft' => true]);

    $this->getJson("/api/cocktails/{$draft->id}/related?limit=5")->assertNotFound();
    $this->getJson('/api/cocktails/999999/related?limit=5')->assertNotFound();
});

it('TC-174-21: 材料が 1 つ・0 個のカクテルを起点にしても、ある材料だけで探す', function () {
    $n = relatedTree();
    $single = relatedCocktail([$n['ラム']]);
    $rum = relatedCocktail([$n['ホワイトラム']]);
    $empty = relatedCocktail([]);

    expect(relatedIds($this->getJson("/api/cocktails/{$single->id}/related?limit=50")))
        ->toBe(relatedExpected([$rum]))
        ->and(relatedIds($this->getJson("/api/cocktails/{$empty->id}/related?limit=50")))
        ->toBe([]);
});

it('TC-174-22: 1 件ごとに、一覧の表に出す項目を返す(同じ名前の API と同じ形)', function () {
    $tree = app(CategoryTree::class);
    $gin = $tree->addNode(null, Category::factory()->make(['name' => 'ジン', 'name_eng' => 'Gin'])->getAttributes());
    $shake = Method::factory()->create(['name' => 'シェイク', 'name_eng' => 'Shake']);
    $cocktail = Cocktail::factory()->create([
        'name' => 'サンダウン', 'name_eng' => 'Sundown', 'base_category_id' => $gin->id, 'method_id' => $shake->id,
        'volume' => 32, 'sweet_degree' => null, 'rate' => null, 'youtube' => null, 'is_major' => false,
    ]);

    $this->getJson("/api/cocktails/random?base_id={$gin->id}&limit=5")
        ->assertOk()
        ->assertJsonPath('data', [[
            'id' => $cocktail->id,
            'name' => 'サンダウン',
            'name_eng' => 'Sundown',
            'is_major' => false,
            'base' => ['id' => $gin->id, 'name' => 'ジン', 'name_eng' => 'Gin'],
            'method' => ['id' => $shake->id, 'name' => 'シェイク'],
            'recipe_count' => 0,
            'strength' => 32,
            'sweet_degree' => null,
            'rate' => null,
            'youtube' => null,
            'updated_at' => $cocktail->updated_at?->toJSON(),
        ]]);
});
