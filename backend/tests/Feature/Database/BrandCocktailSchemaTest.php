<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchemaShape;

// 銘柄・カクテル・材料の表(#22)の形を docs/schema.md と突き合わせ、モデルのリレーションと外部キーの動きを確かめる。
// ノードは CategoryFactory の単体のノードを使い、閉包表(category_closures)には行を入れない。

uses(RefreshDatabase::class)->group('db');

it('TC-22-04: brands の列の型・null 可・既定値が設計どおり', function () {
    expect(SchemaShape::columns('brands'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'category_id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_search' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', false, null],
        'volume' => ['decimal(4,1)', false, null],
        'description' => ['text', true, 'NULL'],
        'is_major' => ['tinyint(1)', false, '0'],
        'is_like' => ['tinyint(1)', false, '0'],
        'instagram' => ['varchar(255)', true, 'NULL'],
        'youtube' => ['varchar(255)', true, 'NULL'],
        'youtube_created_at' => ['datetime', true, 'NULL'],
        'youtube_start_seconds' => ['int(10) unsigned', true, 'NULL'],
        'image' => ['text', true, 'NULL'],
        'image_txt' => ['text', true, 'NULL'],
        'image_url' => ['text', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ]);
});

it('TC-22-05: brands の索引は旧と同じ 7 列と主キーで、category_id は categories を RESTRICT で指す', function () {
    expect(SchemaShape::indexes('brands'))->toEqualCanonicalizing([
        'primary' => ['id'],
        'brands_category_id_index' => ['category_id'],
        'brands_name_index' => ['name'],
        'brands_name_search_index' => ['name_search'],
        'brands_name_eng_index' => ['name_eng'],
        'brands_youtube_created_at_index' => ['youtube_created_at'],
        'brands_is_major_index' => ['is_major'],
        'brands_is_like_index' => ['is_like'],
    ])
        ->and(SchemaShape::foreignKeys('brands'))->toBe([
            ['category_id', 'categories', 'id', 'restrict'],
        ]);
});

it('TC-22-06: categories.famous_brand_id に索引と、brands を RESTRICT で指す外部キーがある', function () {
    expect(SchemaShape::indexes('categories'))->toMatchArray([
        'categories_famous_brand_id_index' => ['famous_brand_id'],
    ])
        ->and(SchemaShape::foreignKeys('categories'))->toBe([
            ['famous_brand_id', 'brands', 'id', 'restrict'],
        ]);
});

it('TC-22-07: cocktails の列の型・null 可・既定値が設計どおり', function () {
    expect(SchemaShape::columns('cocktails'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_search' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', true, 'NULL'],
        'base_category_id' => ['bigint(20) unsigned', false, null],
        'method_id' => ['bigint(20) unsigned', false, null],
        'glass_dictionary_id' => ['bigint(20) unsigned', true, 'NULL'],
        'ice_id' => ['bigint(20) unsigned', true, 'NULL'],
        'volume' => ['decimal(4,1)', false, null],
        'sweet_degree' => ['tinyint(4)', true, 'NULL'],
        'rate' => ['tinyint(3) unsigned', true, 'NULL'],
        'description' => ['text', true, 'NULL'],
        'note' => ['text', true, 'NULL'],
        'is_draft' => ['tinyint(1)', false, '0'],
        'is_major' => ['tinyint(1)', false, '0'],
        'recipe_count' => ['tinyint(3) unsigned', false, '0'],
        'instagram' => ['varchar(255)', true, 'NULL'],
        'youtube' => ['varchar(255)', true, 'NULL'],
        'youtube_created_at' => ['datetime', true, 'NULL'],
        'youtube_start_seconds' => ['int(10) unsigned', true, 'NULL'],
        'image' => ['text', true, 'NULL'],
        'image_txt' => ['text', true, 'NULL'],
        'image_url' => ['text', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ]);
});

it('TC-22-08: cocktails の索引は旧と同じ 11 列と主キーで、外部キーはベースの 1 本だけ', function () {
    expect(SchemaShape::indexes('cocktails'))->toEqualCanonicalizing([
        'primary' => ['id'],
        'cocktails_name_index' => ['name'],
        'cocktails_name_eng_index' => ['name_eng'],
        'cocktails_name_search_index' => ['name_search'],
        'cocktails_base_category_id_index' => ['base_category_id'],
        'cocktails_method_id_index' => ['method_id'],
        'cocktails_glass_dictionary_id_index' => ['glass_dictionary_id'],
        'cocktails_is_draft_index' => ['is_draft'],
        'cocktails_is_major_index' => ['is_major'],
        'cocktails_rate_index' => ['rate'],
        'cocktails_youtube_created_at_index' => ['youtube_created_at'],
        'cocktails_recipe_count_index' => ['recipe_count'],
    ])
        // method_id・glass_dictionary_id・ice_id の外部キーは、指す表(#23)ができてから足す
        ->and(SchemaShape::foreignKeys('cocktails'))->toBe([
            ['base_category_id', 'categories', 'id', 'restrict'],
        ]);
});

it('TC-22-09: cocktail_ingredients の列・主キー・索引が設計どおり', function () {
    expect(SchemaShape::columns('cocktail_ingredients'))->toBe([
        'cocktail_id' => ['bigint(20) unsigned', false, null],
        'position' => ['tinyint(3) unsigned', false, null],
        'category_id' => ['bigint(20) unsigned', false, null],
        'brand_id' => ['bigint(20) unsigned', true, 'NULL'],
        'amount' => ['varchar(20)', false, null],
        'is_optional' => ['tinyint(1)', false, '0'],
    ])
        ->and(SchemaShape::indexes('cocktail_ingredients'))->toEqualCanonicalizing([
            'primary' => ['cocktail_id', 'position'],
            'cocktail_ingredients_category_id_index' => ['category_id'],
            'cocktail_ingredients_brand_id_index' => ['brand_id'],
        ]);
});

it('TC-22-10: cocktail_ingredients の外部キーは、カクテルが CASCADE、ノードと銘柄が RESTRICT', function () {
    expect(SchemaShape::foreignKeys('cocktail_ingredients'))->toEqualCanonicalizing([
        ['cocktail_id', 'cocktails', 'id', 'cascade'],
        ['category_id', 'categories', 'id', 'restrict'],
        ['brand_id', 'brands', 'id', 'restrict'],
    ]);
});

it('TC-22-11: 材料は position の順に返る(入れた順が違っても、まとめて引いても)', function () {
    $mojito = Cocktail::factory()->create();
    $gimlet = Cocktail::factory()->create();
    foreach ([3, 1, 2] as $position) {
        CocktailIngredient::factory()->for($mojito)->create(['position' => $position, 'amount' => "m{$position}"]);
        CocktailIngredient::factory()->for($gimlet)->create(['position' => 4 - $position, 'amount' => 'g'.(4 - $position)]);
    }

    expect($mojito->ingredients->pluck('amount')->all())->toBe(['m1', 'm2', 'm3'])
        ->and($mojito->ingredients()->toRawSql())->toEndWith('order by `position` asc');

    $loaded = Cocktail::with('ingredients')->orderBy('id')->get();

    expect($loaded->map(fn (Cocktail $cocktail) => $cocktail->ingredients->pluck('position')->all())->all())
        ->toBe([[1, 2, 3], [1, 2, 3]]);
});

it('TC-22-12: 材料は、カクテルが違えば同じ position を持て、更新は (カクテル, position) の 1 行だけに効く', function () {
    $first = CocktailIngredient::factory()->create(['position' => 1, 'amount' => '30ml']);
    $second = CocktailIngredient::factory()->create(['position' => 1, 'amount' => '30ml']);

    $second->update(['amount' => '45ml']);

    expect(CocktailIngredient::query()->orderBy('cocktail_id')->pluck('amount', 'cocktail_id')->all())
        ->toBe([$first->cocktail_id => '30ml', $second->cocktail_id => '45ml']);
});

it('TC-22-13: 同じカクテルに同じ position の材料を 2 行入れられない', function () {
    $cocktail = Cocktail::factory()->create();
    CocktailIngredient::factory()->for($cocktail)->create(['position' => 1]);

    expect(fn () => CocktailIngredient::factory()->for($cocktail)->create(['position' => 1]))
        ->toThrow(QueryException::class);
});

it('TC-22-14: 同じカクテルに、同じノード・銘柄で量の違う材料を 2 行入れられる', function () {
    $cocktail = Cocktail::factory()->create();
    $brand = Brand::factory()->create();
    foreach ([1 => '30ml', 2 => '15ml'] as $position => $amount) {
        CocktailIngredient::factory()->for($cocktail)->create([
            'position' => $position, 'category_id' => $brand->category_id, 'brand_id' => $brand->id, 'amount' => $amount,
        ]);
    }

    expect($cocktail->ingredients->pluck('amount')->all())->toBe(['30ml', '15ml']);
});

it('TC-22-15: カクテルを消すと、その材料も消える(ほかのカクテルの材料は残る)', function () {
    $deleted = Cocktail::factory()->create();
    $kept = Cocktail::factory()->create();
    CocktailIngredient::factory()->for($deleted)->create(['position' => 1]);
    CocktailIngredient::factory()->for($deleted)->create(['position' => 2]);
    CocktailIngredient::factory()->for($kept)->create(['position' => 1]);

    $deleted->delete();

    expect(CocktailIngredient::query()->pluck('cocktail_id')->all())->toBe([$kept->id]);
});

it('TC-22-16: 材料・ノードの有名な銘柄に使われている銘柄と、銘柄・材料・ベースに使われているノードは消せない', function (Model $target) {
    expect(fn () => $target->delete())->toThrow(QueryException::class)
        ->and($target->fresh())->not->toBeNull();
})->with([
    '材料の銘柄' => fn () => tap(Brand::factory()->create(), fn (Brand $brand) => CocktailIngredient::factory()->for($brand)->create(['position' => 1])),
    '有名な銘柄' => fn () => tap(Brand::factory()->create(), fn (Brand $brand) => Category::findOrFail($brand->category_id)->update(['famous_brand_id' => $brand->id])),
    '銘柄の所属ノード' => fn () => tap(Category::factory()->create(), fn (Category $category) => Brand::factory()->for($category)->create()),
    '材料のノード' => fn () => tap(Category::factory()->create(), fn (Category $category) => CocktailIngredient::factory()->for($category)->create(['position' => 1])),
    'カクテルのベース' => fn () => tap(Category::factory()->create(), fn (Category $category) => Cocktail::factory()->for($category, 'baseCategory')->create()),
]);

it('TC-22-17: 銘柄を決めない材料は brand_id が null で入り、銘柄のリレーションは null', function () {
    $ingredient = CocktailIngredient::factory()->create(['position' => 1]);

    $loaded = CocktailIngredient::query()->where('cocktail_id', $ingredient->cocktail_id)->firstOrFail();

    expect($loaded->brand_id)->toBeNull()
        ->and($loaded->brand)->toBeNull();
});

it('TC-22-18: 銘柄・カクテルの factory で、既定値の入った行を作って読み戻せる(4 バイト文字も、度数の端の値も欠けない)', function (string $volume) {
    $brand = Brand::factory()->create(['name' => '🍸ラム𩸽', 'volume' => $volume]);
    $cocktail = Cocktail::factory()->create(['name' => '🍹モヒート𩸽', 'volume' => $volume]);

    $loadedBrand = Brand::findOrFail($brand->id);
    $loadedCocktail = Cocktail::findOrFail($cocktail->id);

    expect($loadedBrand->name)->toBe('🍸ラム𩸽')
        ->and($loadedBrand->volume)->toBe($volume)
        ->and($loadedBrand->is_major)->toBeFalse()
        ->and($loadedBrand->is_like)->toBeFalse()
        ->and($loadedCocktail->name)->toBe('🍹モヒート𩸽')
        ->and($loadedCocktail->volume)->toBe($volume)
        ->and($loadedCocktail->is_draft)->toBeFalse()
        ->and($loadedCocktail->is_major)->toBeFalse()
        ->and($loadedCocktail->recipe_count)->toBe(0);
})->with(['0.0', '96.0', '999.9']);

it('TC-22-19: 度数が decimal(4,1) に収まらない値(1000.0)は入らない', function () {
    expect(fn () => Brand::factory()->create(['volume' => '1000.0']))->toThrow(QueryException::class);
});

it('TC-22-20: ノード・銘柄・カクテル・材料のリレーションが、外部キーの指す行を返す', function () {
    $category = Category::factory()->create();
    $base = Category::factory()->create();
    $brand = Brand::factory()->for($category)->create();
    $category->update(['famous_brand_id' => $brand->id]);
    $cocktail = Cocktail::factory()->for($base, 'baseCategory')->create();
    $ingredient = CocktailIngredient::factory()->for($cocktail)->for($category)->for($brand)->create(['position' => 1]);

    expect($category->brands->modelKeys())->toBe([$brand->id])
        ->and($category->famousBrand?->is($brand))->toBeTrue()
        ->and($brand->category?->is($category))->toBeTrue()
        ->and($ingredient->cocktail?->is($cocktail))->toBeTrue()
        ->and($ingredient->category?->is($category))->toBeTrue()
        ->and($ingredient->brand?->is($brand))->toBeTrue()
        ->and($cocktail->ingredients->first()?->is($ingredient))->toBeTrue()
        ->and($cocktail->baseCategory?->is($base))->toBeTrue();
});
