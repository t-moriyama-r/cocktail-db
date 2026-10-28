<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 条件検索の API(POST /api/cocktails/search。#163)の OR 検索と、条件の検証。
// 一致の意味は docs/search.md の「新しい木での意味」(#161)。期待値は、手元で動かした旧の CocktailFactory::cocktailSearch と
// SearchCocktail の検証の結果と、docs/search-expected-diffs.md の「[#163] で変えたところ」から置く。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木。名前は docs/search.md の例に合わせる。
 *
 * 洋酒(根)
 * ├ ラム
 * │  ├ ホワイトラム ── 銘柄: バカルディ
 * │  └ ダークラム
 * └ ジン
 * ソフトドリンク(根。soft_drink)
 * └ コーラ
 * その他(根。other)
 * └ ライム
 *
 * @return array<string, Category>
 */
function cocktailSearchTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['ダークラム'] = $add($n['ラム'], 'ダークラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');
    $n['コーラ'] = $add($n['ソフトドリンク'], 'コーラ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['ライム'] = $add($n['その他'], 'ライム');

    return $n;
}

/**
 * 材料を並べてカクテルを作る。材料は [ノード, 任意か] か、銘柄つきなら [銘柄, 任意か]。
 *
 * @param  list<array{Category|Brand, bool}>  $ingredients
 * @param  array<string, mixed>  $attrs
 */
function cocktailSearchCocktail(string $name, array $ingredients, array $attrs = []): Cocktail
{
    $cocktail = Cocktail::factory()->create(['name' => $name, ...$attrs]);
    foreach ($ingredients as $position => [$item, $isOptional]) {
        $factory = CocktailIngredient::factory();
        $factory = $item instanceof Brand
            ? $factory->forBrand($item)
            : $factory->state(['category_id' => $item->id]);
        $factory->create([
            'cocktail_id' => $cocktail->id,
            'position' => $position + 1,
            'is_optional' => $isOptional,
        ]);
    }

    return $cocktail;
}

/**
 * 検索の API を呼ぶ。
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function cocktailSearchPost(TestCase $test, array $body): TestResponse
{
    return $test->postJson('/api/cocktails/search', $body);
}

/**
 * 名前で引いたノードの ID。
 *
 * @param  array<string, Category>  $nodes
 * @return list<int>
 */
function cocktailSearchIds(array $nodes, string ...$names): array
{
    return array_values(array_map(fn (string $name): int => $nodes[$name]->id, $names));
}

/**
 * 応答のカクテルの名前を、返った順で返す。
 *
 * @param  TestResponse<JsonResponse>  $response
 * @return list<string>
 */
function cocktailSearchNames(TestResponse $response): array
{
    /** @var list<string> $names */
    $names = $response->assertOk()->json('data.*.name');

    return $names;
}

describe('OR 検索の結果', function () {
    beforeEach(function () {
        $this->n = cocktailSearchTree();
        $n = $this->n;
        $this->bacardi = Brand::factory()->create(['category_id' => $n['ホワイトラム']->id]);
        // ID の順に作る。結果は ID の昇順で返る
        cocktailSearchCocktail('キューバ・リブレ', [[$n['ラム'], false], [$n['コーラ'], false], [$n['ライム'], false]]);
        cocktailSearchCocktail('モヒート', [[$n['ホワイトラム'], false], [$n['ライム'], false]]);
        cocktailSearchCocktail('ダイキリ', [[$this->bacardi, false], [$n['ライム'], false]]);
        cocktailSearchCocktail('ラム・コーク', [[$n['ダークラム'], false], [$n['コーラ'], false]]);
        cocktailSearchCocktail('ジン・リッキー', [[$n['ジン'], false], [$n['ライム'], true]]);
    });

    it('TC-163-01: 根の直下のノードを選ぶと、そのノードと配下の材料を使うカクテルが当たり、兄弟の部分木だけのものは当たらない', function () {
        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ラム')])))
            ->toBe(['キューバ・リブレ', 'モヒート', 'ダイキリ', 'ラム・コーク']);
    });

    it('TC-163-02: 葉を選ぶと、そのノードと、祖先(種類を指定しない材料)を使うカクテルが当たり、兄弟だけのものは当たらない', function () {
        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ホワイトラム')])))
            ->toBe(['キューバ・リブレ', 'モヒート', 'ダイキリ']);
    });

    it('TC-163-03: 銘柄つきの材料は、銘柄の所属するノードで当たる', function () {
        // ダイキリの材料はバカルディ(ホワイトラムの銘柄)だけ。ホワイトラムでも、祖先のラムでも当たる
        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ホワイトラム')])))
            ->toContain('ダイキリ')
            ->and(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ラム')])))
            ->toContain('ダイキリ')
            ->and(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ダークラム')])))
            ->not->toContain('ダイキリ');
    });

    it('TC-163-04: 根を選ぶと、根の部分木のすべての材料が一致する', function () {
        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ソフトドリンク')])))
            ->toBe(['キューバ・リブレ', 'ラム・コーク']);
    });

    it('TC-163-05: 複数のノードを選ぶと、どれかと一致する材料を使うカクテル(和)が当たる', function () {
        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ダークラム', 'ジン')])))
            ->toBe(['キューバ・リブレ', 'ラム・コーク', 'ジン・リッキー']);
    });

    it('TC-163-06: 下書きのカクテルは、一致する材料を使っていても返さない', function () {
        cocktailSearchCocktail('下書きのダイキリ', [[$this->n['ホワイトラム'], false]], ['is_draft' => true]);

        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ホワイトラム')])))
            ->toBe(['キューバ・リブレ', 'モヒート', 'ダイキリ']);
    });

    it('TC-163-07: 任意の材料も一致の対象にする', function () {
        // ライムはジン・リッキーでは任意。ほかの 3 つは任意でない材料として使う
        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ライム')])))
            ->toBe(['キューバ・リブレ', 'モヒート', 'ダイキリ', 'ジン・リッキー']);
    });

    it('TC-163-08: mode を省くと OR 検索になる', function () {
        $ids = cocktailSearchIds($this->n, 'ダークラム', 'ジン');

        expect(cocktailSearchNames(cocktailSearchPost($this, ['category_ids' => $ids])))
            ->toBe(cocktailSearchNames(cocktailSearchPost($this, ['mode' => 'or', 'category_ids' => $ids])))
            ->and(cocktailSearchNames(cocktailSearchPost($this, ['mode' => null, 'category_ids' => $ids])))
            ->toBe(['キューバ・リブレ', 'ラム・コーク', 'ジン・リッキー']);
    });

    it('TC-163-09: mode=or を明示すると OR 検索になる', function () {
        expect(cocktailSearchNames(cocktailSearchPost($this, ['mode' => 'or', 'category_ids' => cocktailSearchIds($this->n, 'ダークラム', 'ジン')])))
            ->toBe(['キューバ・リブレ', 'ラム・コーク', 'ジン・リッキー']);
    });

    it('TC-163-16: 一致するカクテルが無ければ、200 で空の配列を返す', function () {
        $unused = app(CategoryTree::class)->addNode(
            $this->n['洋酒'],
            Category::factory()->make(['name' => 'テキーラ'])->getAttributes(),
        );

        cocktailSearchPost($this, ['category_ids' => [$unused->id]])
            ->assertOk()
            ->assertExactJson(['data' => []]);
    });
});

it('TC-163-17: 1 件ごとに一覧の表に出す項目を、ID の昇順で返す', function () {
    $n = cocktailSearchTree();
    $shake = Method::factory()->create(['name' => 'シェイク', 'name_eng' => 'Shake']);
    // 作った順(と名前の順)ではなく ID の順で返ることを見るため、先に大きい ID のカクテルを作る
    cocktailSearchCocktail('アレキサンダー', [[$n['ジン'], false]], ['id' => 900]);
    $first = cocktailSearchCocktail('ギムレット', [[$n['ジン'], false]], [
        'id' => 100, 'name_eng' => 'Gimlet', 'base_category_id' => $n['ジン']->id, 'method_id' => $shake->id,
        'volume' => 25, 'sweet_degree' => null, 'rate' => null, 'youtube' => null, 'is_major' => true,
    ]);

    cocktailSearchPost($this, ['category_ids' => [$n['ジン']->id]])
        ->assertOk()
        ->assertJsonPath('data.*.id', [100, 900])
        ->assertJsonPath('data.0', [
            'id' => 100,
            'name' => 'ギムレット',
            'name_eng' => 'Gimlet',
            'is_major' => true,
            'base' => ['id' => $n['ジン']->id, 'name' => 'ジン', 'name_eng' => $n['ジン']->name_eng],
            'method' => ['id' => $shake->id, 'name' => 'シェイク'],
            'recipe_count' => 0,
            'strength' => 25,
            'sweet_degree' => null,
            'rate' => null,
            'youtube' => null,
            'updated_at' => $first->updated_at?->toJSON(),
        ]);
});

describe('条件の検証', function () {
    beforeEach(function () {
        $this->n = cocktailSearchTree();
    });

    it('TC-163-10: mode が or 以外なら 422', function (mixed $mode) {
        cocktailSearchPost($this, ['mode' => $mode, 'category_ids' => cocktailSearchIds($this->n, 'ラム')])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['mode']);
    })->with([
        '無い方式' => ['xyz'],
        'and(#164 で受ける)' => ['and'],
        'having(#165 で受ける)' => ['having'],
        '大文字' => ['OR'],
        '数' => [1],
        '配列' => [['or']],
    ]);

    it('TC-163-11: category_ids が空の配列なら 422', function () {
        cocktailSearchPost($this, ['category_ids' => []])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
    });

    it('TC-163-12: category_ids を省くと 422', function () {
        cocktailSearchPost($this, ['mode' => 'or'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
    });

    it('TC-163-13: category_ids が配列でなければ 422', function (mixed $value) {
        cocktailSearchPost($this, ['category_ids' => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
    })->with([
        '数' => [1],
        '文字' => ['1,2'],
        'null' => [null],
    ]);

    it('TC-163-14: 無いノードの ID が 1 つでも入っていれば、ほかの ID があっても 422', function () {
        $missing = Category::query()->latest('id')->firstOrFail()->id + 1000;

        cocktailSearchPost($this, ['category_ids' => [$missing]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
        cocktailSearchPost($this, ['category_ids' => [$this->n['ラム']->id, $missing]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
    });

    it('TC-163-15: 整数でない ID が入っていれば 422', function (mixed $value) {
        cocktailSearchPost($this, ['category_ids' => [$this->n['ラム']->id, $value]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids.1']);
    })->with([
        '文字' => ['abc'],
        '小数' => [1.5],
        'null' => [null],
        '配列' => [[1]],
        'int に収まらない数字' => ['99999999999999999999'],
    ]);

    it('TC-163-18: ID が数百あっても、ノードがあるかは 1 本のクエリで確かめる', function () {
        $tree = app(CategoryTree::class);
        $ids = [];
        for ($i = 0; $i < 300; $i++) {
            $ids[] = $tree->addNode($this->n['ジン'], Category::factory()->make()->getAttributes())->id;
        }

        DB::enableQueryLog();
        cocktailSearchPost($this, ['category_ids' => $ids])->assertOk();
        $existenceChecks = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains($query['query'], 'count(') && str_contains($query['query'], '`categories`'),
        );

        expect($existenceChecks)->toHaveCount(1);
    });

    it('TC-163-19: category_ids が 1000 件を超えたら 422、1000 件までは受ける', function () {
        $id = $this->n['ラム']->id;

        cocktailSearchPost($this, ['category_ids' => array_fill(0, 1001, $id)])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
        cocktailSearchPost($this, ['category_ids' => array_fill(0, 1000, $id)])->assertOk();
    });
});
