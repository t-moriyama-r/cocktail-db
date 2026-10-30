<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
use App\Models\Method;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeSignature;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 管理 API のカクテルの削除と、保存のたびに材料の数(recipe_count)・材料の組のハッシュ(recipe_signature)を
// 作り直すこと(#130)。旧の DetailEditController@delete_cocktail と、旧の保存で材料の数を書いていたところの置き換え。
// 旧と変えたところは #130 の本文の「旧との差分」。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * 洋酒(根)── ラム・ジン・ライム の木のノード。
 *
 * @return array<string, Category>
 */
function cocktailDeleteNodes(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'name_search' => NameNormalizer::normalize($name), 'name_eng' => $name.'-eng'])->getAttributes(),
    );

    $root = $add(null, '洋酒');

    return ['ラム' => $add($root, 'ラム'), 'ジン' => $add($root, 'ジン'), 'ライム' => $add($root, 'ライム')];
}

/**
 * $cocktail に材料の行を、配列の順に position を 1 から振って入れる。
 *
 * @param  list<array{0: Category, 1: bool}>  $rows  (ノード, 任意か)
 */
function cocktailDeleteIngredients(Cocktail $cocktail, array $rows): void
{
    foreach ($rows as $i => [$node, $optional]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id, 'category_id' => $node->id, 'position' => $i + 1, 'amount' => '30ml', 'is_optional' => $optional,
        ]);
    }
}

it('TC-130-01: ログインしていないと、削除は 401 で、カクテルは残る', function () {
    auth()->logout();
    $cocktail = Cocktail::factory()->create();

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeTrue();
});

it('TC-130-02: 削除は 204 で、カクテルと、その材料・別名を消す(公開・下書きとも)', function (bool $isDraft) {
    $n = cocktailDeleteNodes();
    $cocktail = Cocktail::factory()->create(['is_draft' => $isDraft]);
    cocktailDeleteIngredients($cocktail, [[$n['ラム'], false], [$n['ライム'], false]]);
    CocktailAlias::factory()->create(['cocktail_id' => $cocktail->id]);

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNoContent();

    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeFalse()
        ->and(CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->count())->toBe(0)
        ->and(CocktailAlias::query()->where('cocktail_id', $cocktail->id)->count())->toBe(0);
})->with([
    '公開' => [false],
    '下書き' => [true],
]);

it('TC-130-03: 削除は、ほかのカクテルとその材料・別名を残す', function () {
    $n = cocktailDeleteNodes();
    [$target, $other] = Cocktail::factory()->count(2)->create()->all();
    cocktailDeleteIngredients($target, [[$n['ラム'], false], [$n['ライム'], false]]);
    cocktailDeleteIngredients($other, [[$n['ジン'], false], [$n['ライム'], false]]);
    CocktailAlias::factory()->create(['cocktail_id' => $other->id, 'name' => '別名']);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->pluck('id')->all())->toBe([$other->id])
        ->and(CocktailIngredient::query()->where('cocktail_id', $other->id)->orderBy('position')->pluck('category_id')->all())
        ->toBe([$n['ジン']->id, $n['ライム']->id])
        ->and(CocktailAlias::query()->where('cocktail_id', $other->id)->pluck('name')->all())->toBe(['別名']);
});

it('TC-130-04: 無い ID・消した後のもう一度の削除は 404', function () {
    $cocktail = Cocktail::factory()->create();

    $this->deleteJson('/api/admin/cocktails/'.($cocktail->id + 1000))->assertNotFound();
    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeTrue();

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNoContent();
    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNotFound();
});

it('TC-130-05: 消したカクテルは、公開の詳細の API で 404 になる', function () {
    $cocktail = Cocktail::factory()->create(['is_draft' => false]);
    $this->getJson("/api/cocktails/{$cocktail->id}")->assertOk();

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNoContent();

    $this->getJson("/api/cocktails/{$cocktail->id}")->assertNotFound();
});

it('TC-130-06: 更新の保存のたびに、材料の行から材料の数と材料の組のハッシュを作り直す', function () {
    $n = cocktailDeleteNodes();
    $method = Method::factory()->create();
    // 同じ材料の組の、公開しているカクテル。保存した後に同じレシピとして引けるかで、ハッシュが作り直されたことを見る
    $twin = Cocktail::factory()->create(['is_draft' => false]);
    cocktailDeleteIngredients($twin, [[$n['ラム'], false], [$n['ライム'], false], [$n['ジン'], true]]);
    $cocktail = Cocktail::factory()->create(['method_id' => $method->id]);
    cocktailDeleteIngredients($cocktail, [[$n['ラム'], false], [$n['ライム'], false], [$n['ジン'], true]]);
    app(RecipeSignature::class)->rewrite([$twin->id]);
    // 列の値を、材料の行と合わない古い値にしておく
    Cocktail::query()->whereKey($cocktail->id)->toBase()->update(['recipe_count' => 9, 'recipe_signature' => str_repeat('0', 64)]);

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", [
        'base_category_id' => $n['ラム']->id, 'name' => 'ダイキリ', 'name_eng' => 'Daiquiri', 'method_id' => $method->id,
        'description' => '説明', 'is_major' => false, 'is_draft' => false,
    ])->assertOk();

    $cocktail->refresh();
    expect($cocktail->recipe_count)->toBe(2)
        ->and($cocktail->sameRecipeCocktails()->pluck('id')->all())->toBe([$twin->id]);
});
