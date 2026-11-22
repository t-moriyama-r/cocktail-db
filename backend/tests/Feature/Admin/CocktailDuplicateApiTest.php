<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeSignature;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 管理 API のカクテルの重複チェック(#129。docs/decisions/admin-screens.md の決まったこと 3)。旧の
// DetailEditController@duplicate_get の置き換えに、同じレシピ(recipe_signature)のカクテルを足したもの。
// 旧と変えたところは #129 の本文の「旧との差分」と docs/decisions/admin-screens.md の「旧との差分」。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * 洋酒(根)
 * ├ ジン ─ ドライジン・オールドトム(銘柄: オールドトム A)
 * └ ラム
 * ソフトドリンク(根)── ソーダ
 * その他(根)── ライム
 *
 * @return array{nodes: array<string, Category>, brand: Brand}
 */
function cocktailDuplicateFixture(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make([
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
            'name_eng' => $name.'-eng',
            'role' => $role,
        ])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ドライジン'] = $add($n['ジン'], 'ドライジン');
    $n['オールドトム'] = $add($n['ジン'], 'オールドトム');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['ライム'] = $add($n['その他'], 'ライム');

    $brand = Brand::factory()->create(['category_id' => $n['オールドトム']->id, 'name' => 'オールドトム A']);

    return ['nodes' => $n, 'brand' => $brand];
}

/**
 * 名前と材料のカクテルを作り、recipe_signature を材料から作る。材料は [ノード, 量, 銘柄] の並び。
 *
 * @param  array<string, mixed>  $attrs
 * @param  list<array{0: Category, 1: string, 2?: Brand}>  $ingredients
 */
function cocktailDuplicateMake(array $attrs, array $ingredients = []): Cocktail
{
    $name = is_string($attrs['name'] ?? null) ? $attrs['name'] : 'カクテル';
    $cocktail = Cocktail::factory()->create(['name_search' => NameNormalizer::normalize($name), ...$attrs]);
    foreach ($ingredients as $i => $row) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'position' => $i + 1,
            'category_id' => $row[0]->id,
            'brand_id' => isset($row[2]) ? $row[2]->id : null,
            'amount' => $row[1],
        ]);
    }
    app(RecipeSignature::class)->rewrite([$cocktail->id]);

    return $cocktail->refresh();
}

/**
 * 重複チェックを呼ぶ。
 *
 * @param  array<array-key, mixed>  $query
 * @return TestResponse<JsonResponse>
 */
function cocktailDuplicateGet(TestCase $test, array $query): TestResponse
{
    return $test->getJson('/api/admin/cocktails/duplicates?'.http_build_query($query));
}

/**
 * 応答の一覧から [ID => match] を並びのまま取り出す。
 *
 * @param  TestResponse<JsonResponse>  $response
 * @return list<array{0: int, 1: string}>
 */
function cocktailDuplicateIds(TestResponse $response, string $key): array
{
    /** @var list<array{id: int, match: string}> $items */
    $items = $response->json($key);

    return array_map(fn (array $item): array => [$item['id'], $item['match']], $items);
}

it('TC-129-01: ログインしていないと 401', function () {
    auth()->logout();

    $this->getJson('/api/admin/cocktails/duplicates?name=ギムレット')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('TC-129-02: 名前も英名も無い・exclude_id が 1 以上の整数でない・名前が 256 文字は 422', function (array $query, array $errors) {
    cocktailDuplicateGet($this, $query)->assertUnprocessable()->assertOnlyJsonValidationErrors($errors);
})->with([
    '名前も英名も無い' => [[], ['name', 'name_eng']],
    '名前も英名も空' => [['name' => '', 'name_eng' => ''], ['name', 'name_eng']],
    'exclude_id が文字' => [['name' => 'ギムレット', 'exclude_id' => 'a'], ['exclude_id']],
    'exclude_id が 0' => [['name' => 'ギムレット', 'exclude_id' => 0], ['exclude_id']],
    '名前が 256 文字' => [['name' => str_repeat('あ', 256)], ['name']],
    '英名が 256 文字' => [['name_eng' => str_repeat('a', 256)], ['name_eng']],
]);

it('TC-129-03: 英名が同じか、検索用の名前が同じカクテルを、下書きも含めて集める。自分と、どちらも違うものは出ない', function () {
    $mine = cocktailDuplicateMake(['name' => 'ギムレット', 'name_eng' => 'Gimlet']);
    $sameName = cocktailDuplicateMake(['name' => 'ギム・レット', 'name_eng' => 'Gimlet No.2']);
    $sameEng = cocktailDuplicateMake(['name' => 'ライム・ジン', 'name_eng' => 'Gimlet']);
    $draft = cocktailDuplicateMake(['name' => 'ギムレット', 'name_eng' => 'Gimlet Draft', 'is_draft' => true]);
    cocktailDuplicateMake(['name' => 'ダイキリ', 'name_eng' => 'Daiquiri']);

    $response = cocktailDuplicateGet($this, ['name' => 'ギムレット', 'name_eng' => 'Gimlet', 'exclude_id' => $mine->id])->assertOk();

    // どれも材料が無いので、材料の近さは同じ(量まで同じ)。同じ近さの中は ID の順
    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([
        [$sameName->id, 'same_amount'],
        [$sameEng->id, 'same_amount'],
        [$draft->id, 'same_amount'],
    ]);
});

it('TC-129-04: 同じ名前のカクテルを、量まで同じ → 材料の組が同じ → 自分の材料をすべて含む → それ以外の順に並べ、同じまとまりは ID の順', function () {
    $n = cocktailDuplicateFixture()['nodes'];
    $mine = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ジン'], '45ml'], [$n['ライム'], '15ml']]);
    $other = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ラム'], '45ml'], [$n['ライム'], '15ml']]);
    $containsAll = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ジン'], '45ml'], [$n['ライム'], '15ml'], [$n['ソーダ'], '30ml']]);
    $sameIngredients = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ライム'], '15ml'], [$n['ジン'], '30ml']]);
    $sameAmount = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ライム'], '15ml'], [$n['ジン'], '45ml']]);
    $noIngredients = cocktailDuplicateMake(['name' => 'ギムレット']);
    $sameAmount2 = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ジン'], '45ml'], [$n['ライム'], '15ml']]);

    $response = cocktailDuplicateGet($this, ['name' => 'ギムレット', 'exclude_id' => $mine->id])->assertOk();

    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([
        [$sameAmount->id, 'same_amount'],
        [$sameAmount2->id, 'same_amount'],
        [$sameIngredients->id, 'same_ingredients'],
        [$containsAll->id, 'contains_all'],
        [$other->id, 'other'],
        [$noIngredients->id, 'other'],
    ]);
});

it('TC-129-05: 材料は根のすぐ下のノード(旧のカテゴリ)で比べ、その下のノード・銘柄は見ない', function () {
    $f = cocktailDuplicateFixture();
    $n = $f['nodes'];
    $mine = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ドライジン'], '45ml'], [$n['ライム'], '15ml']]);
    $oldTom = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['オールドトム'], '45ml', $f['brand']], [$n['ライム'], '15ml']]);
    $gin = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ジン'], '45ml'], [$n['ライム'], '15ml']]);

    $response = cocktailDuplicateGet($this, ['name' => 'ギムレット', 'exclude_id' => $mine->id])->assertOk();

    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([
        [$oldTom->id, 'same_amount'],
        [$gin->id, 'same_amount'],
    ]);
});

it('TC-129-06: exclude_id が無い(新規)・無いカクテルの ID なら、材料の無いカクテルと比べ、同じレシピは空', function (bool $missingId) {
    $n = cocktailDuplicateFixture()['nodes'];
    $withIngredients = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ジン'], '45ml'], [$n['ライム'], '15ml']]);
    $noIngredients = cocktailDuplicateMake(['name' => 'ギムレット']);

    $query = ['name' => 'ギムレット'];
    if ($missingId) {
        $query['exclude_id'] = $noIngredients->id + 1000;
    }
    $response = cocktailDuplicateGet($this, $query)->assertOk();

    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([
        [$noIngredients->id, 'same_amount'],
        [$withIngredients->id, 'contains_all'],
    ]);
    expect($response->json('same_recipe'))->toBe([]);
})->with(['exclude_id を送らない' => false, '無い ID' => true]);

it('TC-129-07: 材料の組(recipe_signature)が同じカクテルを、名前によらず下書きも含めて ID の順に返す。材料の無いカクテルは空', function () {
    $f = cocktailDuplicateFixture();
    $n = $f['nodes'];
    $recipe = [[$n['オールドトム'], '45ml', $f['brand']], [$n['ライム'], '15ml']];
    $mine = cocktailDuplicateMake(['name' => 'ギムレット'], $recipe);
    $draft = cocktailDuplicateMake(['name' => 'ライム・ジン', 'is_draft' => true], array_reverse($recipe));
    $sameNameSameRecipe = cocktailDuplicateMake(['name' => 'ギムレット'], $recipe);
    // 旧のカテゴリと量は同じでも、ノード・銘柄が違えば同じレシピにしない
    cocktailDuplicateMake(['name' => 'ジン・ライム'], [[$n['ドライジン'], '45ml'], [$n['ライム'], '15ml']]);
    cocktailDuplicateMake(['name' => 'ジン・ライム 2'], [[$n['オールドトム'], '30ml', $f['brand']], [$n['ライム'], '15ml']]);

    $response = cocktailDuplicateGet($this, ['name' => 'ギムレット', 'exclude_id' => $mine->id])->assertOk();

    expect(cocktailDuplicateIds($response, 'same_recipe'))->toBe([
        [$draft->id, 'same_amount'],
        [$sameNameSameRecipe->id, 'same_amount'],
    ]);
    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([[$sameNameSameRecipe->id, 'same_amount']]);

    $empty = cocktailDuplicateMake(['name' => 'ギムレット']);
    cocktailDuplicateMake(['name' => 'ダイキリ']);
    expect(cocktailDuplicateGet($this, ['name' => 'ギムレット', 'exclude_id' => $empty->id])->assertOk()->json('same_recipe'))->toBe([]);
});

it('TC-129-08: 英名が空なら英名では集めず、英名の無いカクテルを同じ英名として出さない', function (array $query) {
    $mine = cocktailDuplicateMake(['name' => 'ギムレット', 'name_eng' => null]);
    cocktailDuplicateMake(['name' => 'ダイキリ', 'name_eng' => null]);
    $sameName = cocktailDuplicateMake(['name' => 'ギムレット', 'name_eng' => null]);

    $response = cocktailDuplicateGet($this, [...$query, 'exclude_id' => $mine->id])->assertOk();

    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([[$sameName->id, 'same_amount']]);
})->with([
    '英名を送らない' => [['name' => 'ギムレット']],
    '英名が空' => [['name' => 'ギムレット', 'name_eng' => '']],
]);

it('TC-129-09: 名前を送らず英名だけなら、英名で集める', function () {
    $sameEng = cocktailDuplicateMake(['name' => 'ライム・ジン', 'name_eng' => 'Gimlet']);
    cocktailDuplicateMake(['name' => 'ギムレット', 'name_eng' => 'Gimlet No.2']);

    $response = cocktailDuplicateGet($this, ['name_eng' => 'Gimlet'])->assertOk();

    expect(cocktailDuplicateIds($response, 'same_name'))->toBe([[$sameEng->id, 'same_amount']]);
});

it('TC-129-10: 1 件ごとに、名前・英名・技法・下書きか・近さ・名前を添えた説明と補足・名前つきの材料をレシピの順で返す', function () {
    $f = cocktailDuplicateFixture();
    $n = $f['nodes'];
    $mine = cocktailDuplicateMake(['name' => 'ギムレット'], [[$n['ジン'], '45ml'], [$n['ライム'], '15ml']]);
    $dup = cocktailDuplicateMake([
        'name' => 'ギムレット',
        'name_eng' => 'Gimlet',
        'is_draft' => true,
        'description' => "@n{$n['ジン']->id}@を使う",
        'note' => null,
    ], [[$n['ライム'], '15ml'], [$n['オールドトム'], '45ml', $f['brand']]]);

    cocktailDuplicateGet($this, ['name' => 'ギムレット', 'exclude_id' => $mine->id])
        ->assertOk()
        ->assertExactJson([
            'same_name' => [[
                'id' => $dup->id,
                'name' => 'ギムレット',
                'name_eng' => 'Gimlet',
                'method_id' => $dup->method_id,
                'is_draft' => true,
                'match' => 'same_amount',
                'description' => "@n{$n['ジン']->id}@{!ジン!}を使う",
                'note' => null,
                'ingredients' => [
                    ['category_id' => $n['ライム']->id, 'category_name' => 'ライム', 'brand_id' => null, 'brand_name' => null, 'amount' => '15ml', 'is_optional' => false],
                    ['category_id' => $n['オールドトム']->id, 'category_name' => 'オールドトム', 'brand_id' => $f['brand']->id, 'brand_name' => 'オールドトム A', 'amount' => '45ml', 'is_optional' => false],
                ],
            ]],
            'same_recipe' => [],
        ]);
});
