<?php

use App\Http\Requests\CocktailSearchRequest;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\Method;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 条件検索の API(POST /api/cocktails/search)の、方式によらない絞り込み: 技法(method_ids)とかんたん検索(is_easy)。#166
// 旧は CocktailFactory::cocktailSearch の最後で、材料で選んだカクテルを `whereIn('method', Method)` と
// `recipe_count <= 3`(isEasily のとき)で絞っていた(docs/search.md の「3 つに共通の絞り込み」)。
// 旧と変えたところは docs/search-expected-diffs.md の「[#166] で変えたところ」と docs/decisions/search-screen.md の 6。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木。洋酒(根) └ ラム・ジン・ウォッカ、その他(根。other) └ ライム。
 *
 * @return array<string, Category>
 */
function searchFilterTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ウォッカ'] = $add($n['洋酒'], 'ウォッカ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['ライム'] = $add($n['その他'], 'ライム');

    return $n;
}

/**
 * 材料を並べてカクテルを作り、材料の数(recipe_count)を RecipeCounter で数えて書く。材料は [ノード, 任意か]。
 *
 * @param  list<array{Category, bool}>  $ingredients
 * @param  array<string, mixed>  $attrs
 */
function searchFilterCocktail(string $name, array $ingredients, array $attrs = []): Cocktail
{
    $cocktail = Cocktail::factory()->create(['name' => $name, ...$attrs]);
    foreach ($ingredients as $position => [$node, $isOptional]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'category_id' => $node->id,
            'position' => $position + 1,
            'is_optional' => $isOptional,
        ]);
    }
    app(RecipeCounter::class)->recount([$cocktail->id]);

    return $cocktail;
}

/**
 * 検索の API を呼ぶ。小数を小数のまま本文に載せるよう JSON_PRESERVE_ZERO_FRACTION を付ける。
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function searchFilterPost(TestCase $test, array $body): TestResponse
{
    return $test->postJson('/api/cocktails/search', $body, [], JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * 応答のカクテルの名前を、返った順で返す。
 *
 * @param  TestResponse<JsonResponse>  $response
 * @return list<string>
 */
function searchFilterNames(TestResponse $response): array
{
    /** @var list<string> $names */
    $names = $response->assertOk()->json('data.*.name');

    return $names;
}

describe('技法(method_ids)', function () {
    beforeEach(function () {
        $this->n = searchFilterTree();
        $this->build = Method::factory()->create(['name' => 'ビルド']);
        $this->shake = Method::factory()->create(['name' => 'シェイク']);
        $this->stir = Method::factory()->create(['name' => 'ステア']);
        $this->rum = [$this->n['ラム']->id];
        // ID の順に作る。結果は ID の昇順で返る
        searchFilterCocktail('キューバ・リブレ', [[$this->n['ラム'], false]], ['method_id' => $this->build->id]);
        searchFilterCocktail('ダイキリ', [[$this->n['ラム'], false]], ['method_id' => $this->shake->id]);
        searchFilterCocktail('ラム・マティーニ', [[$this->n['ラム'], false]], ['method_id' => $this->stir->id]);
        // 材料が当たらないカクテルは、技法が合っても出ない
        searchFilterCocktail('ギムレット', [[$this->n['ジン'], false]], ['method_id' => $this->shake->id]);
    });

    it('TC-166-01: 技法を 1 つ選ぶと、材料が当たるカクテルのうち、その技法のものだけが当たる', function () {
        expect(searchFilterNames(searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => [$this->shake->id]])))
            ->toBe(['ダイキリ']);
    });

    it('TC-166-02: 技法を複数選ぶと、どれかの技法のカクテルが当たる', function () {
        expect(searchFilterNames(searchFilterPost($this, [
            'category_ids' => $this->rum,
            'method_ids' => [$this->stir->id, $this->build->id],
        ])))->toBe(['キューバ・リブレ', 'ラム・マティーニ']);
    });

    it('TC-166-03: method_ids を省く・null・空の配列にすると、すべての技法として扱う', function (bool $send, mixed $value) {
        $body = ['category_ids' => $this->rum];
        if ($send) {
            $body['method_ids'] = $value;
        }

        expect(searchFilterNames(searchFilterPost($this, $body)))
            ->toBe(['キューバ・リブレ', 'ダイキリ', 'ラム・マティーニ']);
    })->with([
        '省く' => [false, null],
        'null' => [true, null],
        '空の配列' => [true, []],
    ]);

    it('TC-166-04: すべての技法を選ぶと、省いたときと同じ結果になる', function () {
        expect(searchFilterNames(searchFilterPost($this, [
            'category_ids' => $this->rum,
            'method_ids' => [$this->build->id, $this->shake->id, $this->stir->id],
        ])))->toBe(searchFilterNames(searchFilterPost($this, ['category_ids' => $this->rum])));
    });

    it('TC-166-05: 無い技法の ID は何にも一致しない。ほかの ID があれば、その技法の結果だけを返す', function () {
        $gone = Method::factory()->create();
        $missing = $gone->id;
        $gone->delete();

        searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => [$missing]])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
        expect(searchFilterNames(searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => [$missing, $this->shake->id]])))
            ->toBe(['ダイキリ']);
    });

    it('TC-166-06: 技法の ID は数字の文字でも受ける', function () {
        expect(searchFilterNames(searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => [(string) $this->shake->id]])))
            ->toBe(['ダイキリ']);
    });

    it('TC-166-07: 技法の ID に整数でない値が入っていれば 422', function (mixed $value) {
        searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => [$this->shake->id, $value]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['method_ids.1']);
    })->with([
        '文字' => ['abc'],
        '小数' => [1.5],
        '小数(.0)' => [1.0],
        '真偽値' => [true],
        'null' => [null],
        '配列' => [[1]],
    ]);

    it('TC-166-08: method_ids が配列でなければ 422', function (mixed $value) {
        searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['method_ids']);
    })->with([
        '数' => [1],
        '文字' => ['1'],
        'キーのある配列(オブジェクト)' => [['a' => 1]],
    ]);

    it('TC-166-09: method_ids が 1000 件を超えたら 422、1000 件までは受ける', function () {
        $ids = range(1, CocktailSearchRequest::MAX_METHOD_IDS);

        searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => $ids])->assertOk();
        searchFilterPost($this, ['category_ids' => $this->rum, 'method_ids' => [...$ids, CocktailSearchRequest::MAX_METHOD_IDS + 1]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['method_ids']);
    });
});

describe('かんたん検索(is_easy)', function () {
    beforeEach(function () {
        $this->n = searchFilterTree();
        $n = $this->n;
        $this->rum = [$n['ラム']->id];
        searchFilterCocktail('材料 3 個', [[$n['ラム'], false], [$n['ジン'], false], [$n['ウォッカ'], false]]);
        searchFilterCocktail('材料 4 個', [[$n['ラム'], false], [$n['ジン'], false], [$n['ウォッカ'], false], [$n['ラム'], false]]);
        // 任意の材料と「その他」の材料は数えない(docs/decisions/recipe-count.md)ので、材料の行は 5 つでも 3 個
        searchFilterCocktail('任意・その他を除いて 3 個', [
            [$n['ラム'], false], [$n['ジン'], false], [$n['ウォッカ'], false], [$n['ジン'], true], [$n['ライム'], false],
        ]);
        searchFilterCocktail('材料 1 個', [[$n['ラム'], false]]);
    });

    it('TC-166-10: is_easy=true なら、材料の数が 3 以下のカクテルだけが当たる(3 は当たり、4 は当たらない)', function () {
        expect(Cocktail::query()->orderBy('id')->pluck('recipe_count')->all())->toBe([3, 4, 3, 1])
            ->and(searchFilterNames(searchFilterPost($this, ['category_ids' => $this->rum, 'is_easy' => true])))
            ->toBe(['材料 3 個', '任意・その他を除いて 3 個', '材料 1 個']);
    });

    it('TC-166-11: is_easy を省く・null・false なら、材料の数で絞らない', function (bool $send, ?bool $value) {
        $body = ['category_ids' => $this->rum];
        if ($send) {
            $body['is_easy'] = $value;
        }

        expect(searchFilterNames(searchFilterPost($this, $body)))
            ->toBe(['材料 3 個', '材料 4 個', '任意・その他を除いて 3 個', '材料 1 個']);
    })->with([
        '省く' => [false, null],
        'null' => [true, null],
        'false' => [true, false],
    ]);

    it('TC-166-12: is_easy は 1・0 と、その数字の文字も真偽値として受ける', function (mixed $value, int $count) {
        searchFilterPost($this, ['category_ids' => $this->rum, 'is_easy' => $value])
            ->assertOk()
            ->assertJsonPath('meta.total', $count);
    })->with([
        '1' => [1, 3],
        '"1"' => ['1', 3],
        '0' => [0, 4],
        '"0"' => ['0', 4],
    ]);

    it('TC-166-13: is_easy が真偽値でなければ 422', function (mixed $value) {
        searchFilterPost($this, ['category_ids' => $this->rum, 'is_easy' => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['is_easy']);
    })->with([
        '"true"' => ['true'],
        '"yes"' => ['yes'],
        '2' => [2],
        '配列' => [[true]],
    ]);
});

it('TC-166-14: 技法とかんたん検索を両方指定すると、両方を満たすカクテルだけが当たり、件数もそれで数える', function () {
    $n = searchFilterTree();
    $shake = Method::factory()->create();
    $build = Method::factory()->create();
    searchFilterCocktail('シェイク・3 個', [[$n['ラム'], false], [$n['ジン'], false], [$n['ウォッカ'], false]], ['method_id' => $shake->id]);
    searchFilterCocktail('シェイク・4 個', [[$n['ラム'], false], [$n['ジン'], false], [$n['ウォッカ'], false], [$n['ライム'], true], [$n['ジン'], false]], ['method_id' => $shake->id]);
    searchFilterCocktail('ビルド・1 個', [[$n['ラム'], false]], ['method_id' => $build->id]);
    searchFilterCocktail('下書き・シェイク・1 個', [[$n['ラム'], false]], ['method_id' => $shake->id, 'is_draft' => true]);

    searchFilterPost($this, ['category_ids' => [$n['ラム']->id], 'method_ids' => [$shake->id], 'is_easy' => true, 'per_page' => 1])
        ->assertOk()
        ->assertJsonPath('data.*.name', ['シェイク・3 個'])
        ->assertJsonPath('meta.total', 1);
});
