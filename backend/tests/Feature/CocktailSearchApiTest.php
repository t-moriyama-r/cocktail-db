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

// 条件検索の API(POST /api/cocktails/search。#163・#164)の OR 検索・AND 検索と、条件の検証。
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
 * json_encode は既定で 1.0 を `1` に書くので、小数を小数のまま本文に載せるよう JSON_PRESERVE_ZERO_FRACTION を付ける。
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function cocktailSearchPost(TestCase $test, array $body): TestResponse
{
    return $test->postJson('/api/cocktails/search', $body, [], JSON_PRESERVE_ZERO_FRACTION);
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

/**
 * まとまりを名前で書いて AND 検索し、当たったカクテルの名前を返す。
 *
 * @param  array<string, Category>  $nodes
 * @param  list<list<string>>  $groups
 * @return list<string>
 */
function cocktailSearchAnd(TestCase $test, array $nodes, array $groups): array
{
    return cocktailSearchNames(cocktailSearchPost($test, [
        'mode' => 'and',
        'category_groups' => array_map(fn (array $names): array => cocktailSearchIds($nodes, ...$names), $groups),
    ]));
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

        // #167 でページングを足し、応答に件数(meta)が付いた
        cocktailSearchPost($this, ['category_ids' => [$unused->id]])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    });
});

describe('AND 検索の結果', function () {
    beforeEach(function () {
        $this->n = cocktailSearchTree();
        $n = $this->n;
        // 「ラム」から「ゴールドラム」だけ外す例のため、ラムの子をもう 1 つ足す(docs/search.md の例の木)
        $this->n['ゴールドラム'] = app(CategoryTree::class)->addNode(
            $n['ラム'],
            Category::factory()->make(['name' => 'ゴールドラム'])->getAttributes(),
        );
        $bacardi = Brand::factory()->create(['category_id' => $n['ホワイトラム']->id]);
        // ID の順に作る。結果は ID の昇順で返る
        cocktailSearchCocktail('キューバ・リブレ', [[$n['ラム'], false], [$n['コーラ'], false], [$n['ライム'], false]]);
        cocktailSearchCocktail('モヒート', [[$n['ホワイトラム'], false], [$n['ライム'], false]]);
        cocktailSearchCocktail('ダイキリ', [[$bacardi, false], [$n['ライム'], false]]);
        cocktailSearchCocktail('ラム・コーク', [[$n['ダークラム'], false], [$n['コーラ'], false]]);
        cocktailSearchCocktail('ジン・リッキー', [[$n['ジン'], false], [$n['ライム'], true]]);
        cocktailSearchCocktail('マイタイ', [[$n['ホワイトラム'], false], [$n['ダークラム'], false]]);
        cocktailSearchCocktail('ロングアイランド', [[$n['ホワイトラム'], false], [$n['ジン'], false], [$n['コーラ'], false]]);
    });

    it('TC-164-01: まとまり 2 つは、両方のまとまりに一致する材料を使うカクテル(積)が当たる', function () {
        expect(cocktailSearchAnd($this, $this->n, [['ラム'], ['コーラ']]))
            ->toBe(['キューバ・リブレ', 'ラム・コーク', 'ロングアイランド']);
    });

    it('TC-164-02: まとまり 3 つは、3 つすべてに一致する材料を使うカクテルが当たる', function () {
        // キューバ・リブレはラム(ホワイトラムの祖先)とコーラは満たすが、ジンを使わない
        expect(cocktailSearchAnd($this, $this->n, [['ホワイトラム'], ['ジン'], ['コーラ']]))
            ->toBe(['ロングアイランド']);
    });

    it('TC-164-03: 祖先と子孫を別のまとまりで選ぶと、深いほうだけを選んだのと同じ結果になる', function () {
        $deeperOnly = cocktailSearchAnd($this, $this->n, [['ホワイトラム']]);

        expect(cocktailSearchAnd($this, $this->n, [['ラム'], ['ホワイトラム']]))
            ->toBe($deeperOnly)
            ->toBe(['キューバ・リブレ', 'モヒート', 'ダイキリ', 'マイタイ', 'ロングアイランド']);
    });

    it('TC-164-04: どれか 1 つのまとまりに一致するカクテルが無ければ、200 で空の配列を返す', function () {
        $unused = app(CategoryTree::class)->addNode(
            $this->n['洋酒'],
            Category::factory()->make(['name' => 'テキーラ'])->getAttributes(),
        );

        // #167 でページングを足し、応答に件数(meta)が付いた(TC-163-16 と同じ確かめ方)
        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [[$this->n['ラム']->id], [$unused->id]]])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    });

    it('TC-164-05: まとまりごとには当たるカクテルがあっても、全部を同時に満たすものが無ければ空', function () {
        expect(cocktailSearchAnd($this, $this->n, [['ダークラム'], ['ジン']]))->toBe([]);
    });

    it('TC-164-06: 1 つのまとまりの中は、どれか 1 つを使えばよい(親を選んで子を 1 つ外したとき)', function () {
        // 「ラム」から「ゴールドラム」だけ外すと、画面はホワイトラム・ダークラムを 1 つのまとまりにして送る(#282 の 1)
        expect(cocktailSearchAnd($this, $this->n, [['ホワイトラム', 'ダークラム']]))
            ->toBe(['キューバ・リブレ', 'モヒート', 'ダイキリ', 'ラム・コーク', 'マイタイ', 'ロングアイランド']);
    });

    it('TC-164-07: 同じノードを別々のまとまりにすると、両方を使うカクテルだけになる。1 つの材料はいくつものまとまりを満たしてよい', function () {
        // キューバ・リブレの「ラム」(種類を指定しない)は、ホワイトラム・ダークラムのどちらのまとまりにも一致する
        expect(cocktailSearchAnd($this, $this->n, [['ホワイトラム'], ['ダークラム']]))
            ->toBe(['キューバ・リブレ', 'マイタイ']);
    });

    it('TC-164-08: まとまり 1 つは、同じノードの OR 検索と同じ結果になる', function () {
        $ids = cocktailSearchIds($this->n, 'ダークラム', 'ジン');

        expect(cocktailSearchNames(cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [$ids]])))
            ->toBe(cocktailSearchNames(cocktailSearchPost($this, ['mode' => 'or', 'category_ids' => $ids])))
            ->toBe(['キューバ・リブレ', 'ラム・コーク', 'ジン・リッキー', 'マイタイ', 'ロングアイランド']);
    });

    it('TC-164-09: 任意の材料も一致の対象にする', function () {
        // ライムはジン・リッキーでは任意
        expect(cocktailSearchAnd($this, $this->n, [['ジン'], ['ライム']]))->toBe(['ジン・リッキー']);
    });

    it('TC-164-10: 下書きのカクテルは、すべてのまとまりに一致しても返さない', function () {
        cocktailSearchCocktail('下書きのラム・コーク', [[$this->n['ダークラム'], false], [$this->n['コーラ'], false]], ['is_draft' => true]);

        // キューバ・リブレは、種類を指定しない「ラム」(ダークラムの祖先)とコーラで当たる
        expect(cocktailSearchAnd($this, $this->n, [['ダークラム'], ['コーラ']]))
            ->toBe(['キューバ・リブレ', 'ラム・コーク']);
    });
});

describe('AND 検索の条件の検証', function () {
    beforeEach(function () {
        $this->n = cocktailSearchTree();
    });

    it('TC-164-11: mode=and で category_groups を省くと 422。category_ids では受けない', function () {
        cocktailSearchPost($this, ['mode' => 'and'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups']);
        cocktailSearchPost($this, ['mode' => 'and', 'category_ids' => cocktailSearchIds($this->n, 'ラム')])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups', 'category_ids']);
    });

    it('TC-164-12: mode=and で category_ids も一緒に送ると 422', function () {
        cocktailSearchPost($this, [
            'mode' => 'and',
            'category_groups' => [cocktailSearchIds($this->n, 'ラム')],
            'category_ids' => cocktailSearchIds($this->n, 'ラム'),
        ])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
    });

    it('TC-164-19: 方式に合わない項目は、空の配列でも 422', function () {
        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [cocktailSearchIds($this->n, 'ラム')], 'category_ids' => []])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
        cocktailSearchPost($this, ['category_ids' => cocktailSearchIds($this->n, 'ラム'), 'category_groups' => []])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups']);
    });

    it('TC-164-13: or(mode を省いたときも)で category_groups を送ると 422', function (?string $mode) {
        $body = [
            'category_ids' => cocktailSearchIds($this->n, 'ラム'),
            'category_groups' => [cocktailSearchIds($this->n, 'ラム')],
        ];
        if ($mode !== null) {
            $body['mode'] = $mode;
        }

        cocktailSearchPost($this, $body)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups']);
    })->with([
        'or' => ['or'],
        '省く' => [null],
    ]);

    it('TC-164-14: category_groups が空の配列・配列でなければ 422', function (mixed $value) {
        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups']);
    })->with([
        '空の配列' => [[]],
        '数' => [1],
        '文字' => ['1,2'],
        'null' => [null],
        'キーのある連想配列' => [['a' => [1]]],
    ]);

    it('TC-164-15: まとまりが空・配列でなければ、そのまとまりが 422', function (mixed $group) {
        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [[$this->n['ラム']->id], $group]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups.1']);
    })->with([
        '空の配列' => [[]],
        '数' => [1],
        '文字' => ['1'],
        'null' => [null],
    ]);

    it('TC-164-16: まとまりに整数でない ID が入っていれば 422', function (mixed $value) {
        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [[$this->n['ラム']->id, $value]]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups.0.1']);
    })->with([
        '文字' => ['abc'],
        '整数と同じ値の小数' => [1.0],
        '真偽値' => [true],
        'null' => [null],
        '配列' => [[1]],
    ]);

    it('TC-164-17: どれかのまとまりに無いノードの ID が 1 つでも入っていれば 422', function () {
        $missing = Category::query()->latest('id')->firstOrFail()->id + 1000;

        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [[$this->n['ラム']->id], [$this->n['コーラ']->id, $missing]]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups']);
    });

    it('TC-164-18: ID の総数が 1000 個を超えたら 422、1000 個までは受ける', function () {
        $id = $this->n['ラム']->id;

        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [array_fill(0, 500, $id), array_fill(0, 501, $id)]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_groups']);
        cocktailSearchPost($this, ['mode' => 'and', 'category_groups' => [array_fill(0, 500, $id), array_fill(0, 500, $id)]])
            ->assertOk();
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

    it('TC-163-10: mode が or・and 以外なら 422', function (mixed $mode) {
        cocktailSearchPost($this, ['mode' => $mode, 'category_ids' => cocktailSearchIds($this->n, 'ラム')])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['mode']);
    })->with([
        '無い方式' => ['xyz'],
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
        '整数と同じ値の小数' => [1.0],
        '真偽値' => [true],
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

    it('TC-163-20: category_ids が 1000 件を超えたら 422、1000 件までは受ける', function () {
        $id = $this->n['ラム']->id;

        cocktailSearchPost($this, ['category_ids' => array_fill(0, 1001, $id)])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['category_ids']);
        cocktailSearchPost($this, ['category_ids' => array_fill(0, 1000, $id)])->assertOk();
    });
});

// 並べ替えとページング(#167)。並べ替えの比べ方は旧の画面(Results/Tables/Cocktails.vue)の sortedList を写したもの
// (docs/search.md の「並べ替え(画面)」)。値の無いものの位置などは docs/decisions/search-sort.md の「旧との差分」。

/**
 * 並べ替えを確かめるカクテル。どれもジンを使い、ID は 1〜6。
 *
 * | ID | 名前 | 英名 | ベース | 技法 | 必須 | 甘さ | 評価 | 度数 | 動画 | 更新日時 |
 * |---|---|---|---|---|---|---|---|---|---|---|
 * | 1 | ギムレット | Gimlet | ジン | 2 | 2 | 2 | 3 | 25.0 | - | 2026-01-01 |
 * | 2 | ぎむれっと | gimlet | じん | 1 | 3 | - | 5 | 9.5 | あり | 2026-03-01 |
 * | 3 | Ａ5 | - | ラム | 3 | 2 | 4 | - | 40.0 | あり | 2026-02-01 |
 * | 4 | A4 | Apple | ウォッカ | 1 | 1 | 2 | 3 | 10.0 | - | 2026-04-01 |
 * | 5 | アレキサンダー | ALEXANDER | ブランデー | 2 | 4 | - | - | 100.0 | あり | 2026-03-01 |
 * | 6 | モヒート | - | ラム | 3 | 3 | 1 | 1 | 9.5 | - | 2026-05-01 |
 *
 * @return list<int> 選ぶノードの ID
 */
function cocktailSearchSortFixture(): array
{
    $n = cocktailSearchTree();
    $methods = [];
    foreach ([1, 2, 3] as $id) {
        $methods[$id] = Method::factory()->create(['id' => $id])->id;
    }
    $bases = [];
    foreach (['ジン', 'じん', 'ラム', 'ウォッカ', 'ブランデー'] as $name) {
        $bases[$name] = Category::factory()->create(['name' => $name])->id;
    }

    $rows = [
        [1, 'ギムレット', 'Gimlet', 'ジン', 2, 2, 2, 3, 25.0, null, '2026-01-01 00:00:00'],
        [2, 'ぎむれっと', 'gimlet', 'じん', 1, 3, null, 5, 9.5, 'yt-2', '2026-03-01 00:00:00'],
        [3, 'Ａ5', null, 'ラム', 3, 2, 4, null, 40.0, 'yt-3', '2026-02-01 00:00:00'],
        [4, 'A4', 'Apple', 'ウォッカ', 1, 1, 2, 3, 10.0, null, '2026-04-01 00:00:00'],
        [5, 'アレキサンダー', 'ALEXANDER', 'ブランデー', 2, 4, null, null, 100.0, 'yt-5', '2026-03-01 00:00:00'],
        [6, 'モヒート', null, 'ラム', 3, 3, 1, 1, 9.5, null, '2026-05-01 00:00:00'],
    ];
    foreach ($rows as [$id, $name, $nameEng, $base, $method, $recipeCount, $sweet, $rate, $volume, $youtube, $updatedAt]) {
        cocktailSearchCocktail($name, [[$n['ジン'], false]], [
            'id' => $id, 'name_eng' => $nameEng, 'base_category_id' => $bases[$base], 'method_id' => $methods[$method],
            'recipe_count' => $recipeCount, 'sweet_degree' => $sweet, 'rate' => $rate, 'volume' => $volume,
            'youtube' => $youtube, 'updated_at' => $updatedAt,
        ]);
    }

    return [$n['ジン']->id];
}

/**
 * 応答のカクテルの ID を、返った順で返す。
 *
 * @param  TestResponse<JsonResponse>  $response
 * @return list<int>
 */
function cocktailSearchResultIds(TestResponse $response): array
{
    /** @var list<int> $ids */
    $ids = $response->assertOk()->json('data.*.id');

    return $ids;
}

/**
 * 同じノードを使うカクテルを $count 件、ID 1 から作る。技法とベースは 1 つを使い回す。
 *
 * @return list<int> 選ぶノードの ID
 */
function cocktailSearchManyCocktails(int $count): array
{
    $n = cocktailSearchTree();
    $method = Method::factory()->create();
    foreach (range(1, $count) as $id) {
        cocktailSearchCocktail(sprintf('かくてる%02d', $id), [[$n['ジン'], false]], [
            'id' => $id, 'method_id' => $method->id, 'base_category_id' => $n['ジン']->id,
        ]);
    }

    return [$n['ジン']->id];
}

describe('並べ替え', function () {
    beforeEach(function () {
        $this->ids = cocktailSearchSortFixture();
    });

    it('TC-167-01: sort・order を省くと ID の昇順', function () {
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids])))
            ->toBe([1, 2, 3, 4, 5, 6]);
    });

    it('TC-167-02: 列ごとに、order を省くと旧の画面で見出しを最初に押したときの向きで並ぶ', function (string $sort, array $expected) {
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => $sort])))
            ->toBe($expected);
    })->with([
        'id(昇順)' => ['id', [1, 2, 3, 4, 5, 6]],
        '製法(昇順。技法の ID)' => ['method', [2, 4, 1, 5, 3, 6]],
        '名前(昇順)' => ['name', [4, 3, 5, 1, 2, 6]],
        '英名(昇順)' => ['name_eng', [5, 4, 1, 2, 3, 6]],
        'ベース(昇順)' => ['base', [4, 1, 2, 5, 3, 6]],
        '必須(昇順)' => ['recipe_count', [4, 1, 3, 2, 6, 5]],
        '甘さ(降順)' => ['sweet_degree', [3, 1, 4, 6, 2, 5]],
        '評価(降順)' => ['rate', [2, 1, 4, 6, 3, 5]],
        '度数(降順)' => ['strength', [5, 3, 1, 4, 2, 6]],
        '動画(降順)' => ['youtube', [2, 5, 3, 1, 4, 6]],
    ]);

    it('TC-167-03: order を書くと、その向きで並ぶ', function (string $sort, string $order, array $expected) {
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => $sort, 'order' => $order])))
            ->toBe($expected);
    })->with([
        'id 降順' => ['id', 'desc', [6, 5, 4, 3, 2, 1]],
        '製法 降順' => ['method', 'desc', [3, 6, 1, 5, 2, 4]],
        '名前 降順' => ['name', 'desc', [6, 1, 2, 5, 3, 4]],
        '英名 降順' => ['name_eng', 'desc', [1, 2, 4, 5, 3, 6]],
        'ベース 降順' => ['base', 'desc', [3, 6, 5, 1, 2, 4]],
        '必須 降順' => ['recipe_count', 'desc', [5, 2, 6, 1, 3, 4]],
        '甘さ 昇順' => ['sweet_degree', 'asc', [6, 1, 4, 3, 2, 5]],
        '評価 昇順' => ['rate', 'asc', [6, 1, 4, 2, 3, 5]],
        '度数 昇順' => ['strength', 'asc', [2, 6, 4, 1, 3, 5]],
        '動画 昇順' => ['youtube', 'asc', [3, 2, 5, 1, 4, 6]],
        '名前 昇順(明示)' => ['name', 'asc', [4, 3, 5, 1, 2, 6]],
    ]);

    it('TC-167-04: sort を省いて order=desc なら ID の降順', function () {
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'order' => 'desc'])))
            ->toBe([6, 5, 4, 3, 2, 1]);
    });

    it('TC-167-05: 名前・ベースはカタカナをひらがなに、全角の英数字を半角にそろえて比べる', function () {
        // ギムレット(1)とぎむれっと(2)は同じ値で ID の順。Ａ5(3)は A5 として A4(4)の後
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => 'name'])))
            ->toBe([4, 3, 5, 1, 2, 6])
            // ベースのジン(1)とじん(2)は同じ値
            ->and(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => 'base'])))
            ->toBe([4, 1, 2, 5, 3, 6]);
    });

    it('TC-167-06: 英名は大文字・小文字を区別しない。英名の無いものは向きによらず最後', function () {
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => 'name_eng', 'order' => 'asc'])))
            ->toBe([5, 4, 1, 2, 3, 6])
            ->and(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => 'name_eng', 'order' => 'desc'])))
            ->toBe([1, 2, 4, 5, 3, 6]);
    });

    it('TC-167-07: 度数は数として比べる(9.5 < 10.0 < 100.0)', function () {
        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => 'strength', 'order' => 'asc'])))
            ->toBe([2, 6, 4, 1, 3, 5]);
    });

    it('TC-167-08: 同じ値のものは、どちらの向きでも ID の昇順', function (string $sort) {
        $asc = cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => $sort, 'order' => 'asc']));
        $desc = cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => $sort, 'order' => 'desc']));

        // 名前の 1 と 2、評価の 1 と 4、動画の 2 と 5(更新日時が同じ)は、どちらの向きでも ID の順に並ぶ
        $pairs = ['name' => [1, 2], 'rate' => [1, 4], 'youtube' => [2, 5]][$sort];
        foreach ([$asc, $desc] as $ids) {
            expect(array_values(array_intersect($ids, $pairs)))->toBe($pairs);
        }
    })->with(['name', 'rate', 'youtube']);

    it('TC-167-09: 同じ値がページの境目をまたいでも、ページをつなぐと抜けも重なりも無い', function () {
        // 名前の昇順は [4, 3, 5, 1, 2, 6]。同じ値の 1 と 2 が 2 ページ目と 3 ページ目に分かれる
        $pages = [];
        foreach ([1, 2, 3] as $page) {
            $pages[] = cocktailSearchResultIds(cocktailSearchPost($this, [
                'category_ids' => $this->ids, 'sort' => 'name', 'page' => $page, 'per_page' => 2,
            ]));
        }

        expect($pages)->toBe([[4, 3], [5, 1], [2, 6]]);
    });

    it('TC-167-10: sort が決まった列でなければ 422', function (mixed $sort) {
        cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => $sort])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['sort']);
    })->with([
        '無い列' => ['volume'],
        '大文字' => ['NAME'],
        '数' => [1],
        '配列' => [['name']],
    ]);

    it('TC-167-11: order が asc・desc でなければ 422', function (mixed $order) {
        cocktailSearchPost($this, ['category_ids' => $this->ids, 'sort' => 'name', 'order' => $order])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['order']);
    })->with([
        '無い値' => ['up'],
        '大文字' => ['ASC'],
        '数' => [1],
    ]);
});

describe('ページング', function () {
    it('TC-167-12: per_page は 1〜50。範囲の外は 422', function (int $perPage, ?int $count) {
        $ids = cocktailSearchManyCocktails(51);

        $response = cocktailSearchPost($this, ['category_ids' => $ids, 'per_page' => $perPage]);

        if ($count === null) {
            $response->assertUnprocessable()->assertOnlyJsonValidationErrors(['per_page']);

            return;
        }

        $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('meta.per_page', $perPage);
    })->with([
        '0' => [0, null],
        '1' => [1, 1],
        '50' => [50, 50],
        '51' => [51, null],
    ]);

    it('TC-167-13: per_page を省くと 50 件ずつ。最後のページに残りを、件数を meta に返す', function () {
        $ids = cocktailSearchManyCocktails(51);

        cocktailSearchPost($this, ['category_ids' => $ids])
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.49.id', 50)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.last_page', 2);

        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $ids, 'page' => 2])))->toBe([51]);
    });

    it('TC-167-14: 最後のページの次のページ(とその先)は、空の data と件数を返す', function (int $page) {
        $ids = cocktailSearchManyCocktails(3);

        cocktailSearchPost($this, ['category_ids' => $ids, 'per_page' => 2, 'page' => $page])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', $page);
    })->with([
        '最後の次' => [3],
        'ずっと先' => [PHP_INT_MAX],
    ]);

    it('TC-167-15: page・per_page は数字の文字でも受ける', function () {
        $ids = cocktailSearchManyCocktails(3);

        expect(cocktailSearchResultIds(cocktailSearchPost($this, ['category_ids' => $ids, 'page' => '2', 'per_page' => '2'])))
            ->toBe([3]);
    });

    it('TC-167-16: page が 1 以上の整数でなければ 422', function (mixed $page) {
        $ids = cocktailSearchManyCocktails(1);

        cocktailSearchPost($this, ['category_ids' => $ids, 'page' => $page])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['page']);
    })->with([
        '0' => [0],
        '負' => [-1],
        '文字' => ['abc'],
        '小数' => [1.5],
        '整数と同じ値の小数' => [1.0],
        '真偽値' => [true],
        'int に収まらない数字' => ['99999999999999999999'],
    ]);

    it('TC-167-17: per_page が整数でなければ 422', function (mixed $perPage) {
        $ids = cocktailSearchManyCocktails(1);

        cocktailSearchPost($this, ['category_ids' => $ids, 'per_page' => $perPage])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['per_page']);
    })->with([
        '文字' => ['abc'],
        '整数と同じ値の小数' => [10.0],
        '真偽値' => [true],
    ]);
});
