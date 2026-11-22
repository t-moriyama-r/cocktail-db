<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\RecipeSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 材料の組のハッシュ(cocktails.recipe_signature。#82)を作る RecipeSignature を、MariaDB に保存した材料の行で確かめる。

uses(RefreshDatabase::class)->group('db');

/**
 * 材料を並べてカクテルを作り、recipe_signature を作り直して読み直す。材料は [ノード, 銘柄か null, 量, 任意か]。
 *
 * @param  list<array{Category, Brand|null, string, bool}>  $rows
 */
function recipeSignatureCocktail(array $rows): Cocktail
{
    $cocktail = Cocktail::factory()->create();
    foreach ($rows as $position => [$node, $brand, $amount, $optional]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id,
            'position' => $position + 1,
            'category_id' => $node->id,
            'brand_id' => $brand?->id,
            'amount' => $amount,
            'is_optional' => $optional,
        ]);
    }
    app(RecipeSignature::class)->rewrite([$cocktail->id]);

    return $cocktail->refresh();
}

it('TC-82-03: 材料の組が同じなら、行の並び順(position)が違っても同じ値になる', function () {
    $gin = Category::factory()->create();
    $tonic = Category::factory()->create();
    $brand = Brand::factory()->create(['category_id' => $gin->id]);

    $a = recipeSignatureCocktail([[$gin, $brand, '45ml', false], [$tonic, null, '適量', false]]);
    $b = recipeSignatureCocktail([[$tonic, null, '適量', false], [$gin, $brand, '45ml', false]]);

    expect($a->recipe_signature)->toBeString()->toHaveLength(64)
        ->and($b->recipe_signature)->toBe($a->recipe_signature);
});

it('TC-82-04: ノード・銘柄・量・任意かのどれか 1 つでも違えば、違う値になる', function (string $changed) {
    $gin = Category::factory()->create();
    $vodka = Category::factory()->create();
    $tonic = Category::factory()->create();
    $brand = Brand::factory()->create(['category_id' => $gin->id]);
    $base = [[$gin, $brand, '45ml', false], [$tonic, null, '適量', false]];
    $other = $base;
    $other[0] = match ($changed) {
        'ノード' => [$vodka, $brand, '45ml', false],
        '銘柄' => [$gin, null, '45ml', false],
        '量' => [$gin, $brand, '30ml', false],
        '任意か' => [$gin, $brand, '45ml', true],
        default => throw new InvalidArgumentException("知らない列: {$changed}"),
    };

    expect(recipeSignatureCocktail($other)->recipe_signature)
        ->not->toBe(recipeSignatureCocktail($base)->recipe_signature);
})->with(['ノード', '銘柄', '量', '任意か']);

it('TC-82-05: 材料の無いカクテルは null', function () {
    expect(recipeSignatureCocktail([])->recipe_signature)->toBeNull();
});

it('TC-82-06: 同じ材料の行が 2 つあるカクテルと 1 つのカクテルは、違う値になる', function () {
    $gin = Category::factory()->create();

    $once = recipeSignatureCocktail([[$gin, null, '30ml', false]]);
    $twice = recipeSignatureCocktail([[$gin, null, '30ml', false], [$gin, null, '30ml', false]]);

    expect($twice->recipe_signature)->not->toBe($once->recipe_signature);
});

it('TC-82-07: 量の区切りの文字(" , :)の違いでも、組を取り違えない', function () {
    $gin = Category::factory()->create();
    $tonic = Category::factory()->create();

    $a = recipeSignatureCocktail([[$gin, null, '1,2', false], [$tonic, null, '3', false]]);
    $b = recipeSignatureCocktail([[$gin, null, '1', false], [$tonic, null, '2,3', false]]);

    expect($a->recipe_signature)->not->toBe($b->recipe_signature);
});

it('TC-82-08: 材料を変えて作り直すと値が変わり、材料をすべて消して作り直すと null になる。updated_at は変えない', function () {
    $gin = Category::factory()->create();
    $tonic = Category::factory()->create();
    $cocktail = recipeSignatureCocktail([[$gin, null, '45ml', false], [$tonic, null, '適量', false]]);
    $before = $cocktail->recipe_signature;
    $updatedAt = $cocktail->updated_at?->toJSON();
    $this->travel(1)->minutes();

    CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->where('position', 1)->update(['amount' => '30ml']);
    app(RecipeSignature::class)->rewrite([$cocktail->id]);
    $changed = $cocktail->refresh()->recipe_signature;

    CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->delete();
    app(RecipeSignature::class)->rewrite([$cocktail->id]);

    expect($changed)->toBeString()->not->toBe($before)
        ->and($cocktail->refresh()->recipe_signature)->toBeNull()
        ->and($cocktail->updated_at?->toJSON())->toBe($updatedAt);
});

it('TC-82-09: 作り直すのは渡したカクテルだけで、ほかのカクテルの値は変えない', function () {
    $gin = Category::factory()->create();
    $target = recipeSignatureCocktail([[$gin, null, '45ml', false]]);
    $other = recipeSignatureCocktail([[$gin, null, '45ml', false]]);
    CocktailIngredient::query()->update(['amount' => '30ml']);

    app(RecipeSignature::class)->rewrite([$target->id]);

    expect($target->refresh()->recipe_signature)->not->toBe($other->refresh()->recipe_signature)
        ->and(app(RecipeSignature::class)->signaturesByCocktail([$other->id]))
        ->toBe([$other->id => $target->recipe_signature]);
});
