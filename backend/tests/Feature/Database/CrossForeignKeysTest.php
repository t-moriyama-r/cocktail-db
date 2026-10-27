<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Models\Ice;
use App\Models\Method;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchemaShape;

// 銘柄・カクテル(#22)と技法・氷・用語辞典・アフィリエイト(#23)のあいだの外部キー(#325)を、
// docs/schema.md の「外部キーと削除」と突き合わせ、行を入れて削除のときの動きを確かめる。

uses(RefreshDatabase::class)->group('db');

/**
 * カクテルが指す先(技法・グラス・氷)ごとに、指す先の行を作って、カクテルの列の名前と一緒に返す。
 *
 * @return array{string, Method|Dictionary|Ice}
 */
function cocktailTarget(string $kind): array
{
    return match ($kind) {
        'method' => ['method_id', Method::factory()->create()],
        'glass' => ['glass_dictionary_id', Dictionary::factory()->create()],
        'ice' => ['ice_id', Ice::create(['name' => 'クラッシュド・アイス', 'name_eng' => 'Crushed Ice'])],
        default => throw new InvalidArgumentException($kind),
    };
}

dataset('cocktail targets', ['技法' => 'method', 'グラス' => 'glass', '氷' => 'ice']);

it('TC-325-01: cocktails の method_id・glass_dictionary_id・ice_id に、methods・dictionaries・ices を RESTRICT で指す外部キーがある', function () {
    $keys = collect(SchemaShape::foreignKeys('cocktails'))
        ->reject(fn (array $key) => $key[0] === 'base_category_id')
        ->values()
        ->all();

    expect($keys)->toEqualCanonicalizing([
        ['method_id', 'methods', 'id', 'restrict'],
        ['glass_dictionary_id', 'dictionaries', 'id', 'restrict'],
        ['ice_id', 'ices', 'id', 'restrict'],
    ]);
});

it('TC-325-02: affiliates の brand_id に、brands を CASCADE で指す外部キーがある', function () {
    expect(SchemaShape::foreignKeys('affiliates'))->toContain(['brand_id', 'brands', 'id', 'cascade']);
});

it('TC-325-03: カクテルが使っている技法・グラス・氷は消せず、カクテルも残る', function (string $kind) {
    [$column, $target] = cocktailTarget($kind);
    $cocktail = Cocktail::factory()->create([$column => $target->id]);

    expect(fn () => $target->delete())->toThrow(QueryException::class)
        ->and($target::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and(Cocktail::findOrFail($cocktail->id)->{$column})->toBe($target->id);
})->with('cocktail targets');

it('TC-325-04: どのカクテルも使っていない技法・グラス・氷は消せる', function (string $kind) {
    [, $target] = cocktailTarget($kind);
    Cocktail::factory()->create();

    $target->delete();

    expect($target::query()->whereKey($target->id)->exists())->toBeFalse();
})->with('cocktail targets');

it('TC-325-05: 無い技法・グラス・氷を指すカクテルは入れられない', function (string $kind) {
    [$column, $target] = cocktailTarget($kind);
    $missing = $target->id + 1;

    expect(fn () => Cocktail::factory()->create([$column => $missing]))->toThrow(QueryException::class)
        ->and(Cocktail::query()->count())->toBe(0);
})->with('cocktail targets');

it('TC-325-06: 銘柄を消すと、その銘柄のアフィリエイトが消え、同じノードのノードの行とほかの銘柄の行は残る', function () {
    $deleted = Brand::factory()->create();
    $kept = Brand::factory()->create(['category_id' => $deleted->category_id]);
    $nodeRow = Affiliate::factory()->create(['category_id' => $deleted->category_id]);
    $deletedRow = Affiliate::factory()->create(['category_id' => $deleted->category_id, 'brand_id' => $deleted->id]);
    $keptRow = Affiliate::factory()->create(['category_id' => $deleted->category_id, 'brand_id' => $kept->id]);

    $deleted->delete();

    expect(Affiliate::query()->orderBy('id')->pluck('id')->all())->toBe([$nodeRow->id, $keptRow->id])
        ->and(Affiliate::query()->whereKey($deletedRow->id)->exists())->toBeFalse()
        ->and(Affiliate::findOrFail($nodeRow->id)->brand_key)->toBe(0)
        ->and(Affiliate::findOrFail($keptRow->id)->brand_key)->toBe($kept->id);
});

it('TC-325-07: 銘柄を消した後、同じノードに同じ ID の銘柄の行が入れ直せる(生成列の unique に消えた行が残らない)', function () {
    $brand = Brand::factory()->create();
    Affiliate::factory()->create(['category_id' => $brand->category_id, 'brand_id' => $brand->id]);
    $brandId = $brand->id;
    $categoryId = $brand->category_id;

    $brand->delete();
    Brand::factory()->create(['id' => $brandId, 'category_id' => $categoryId]);
    Affiliate::factory()->create(['category_id' => $categoryId, 'brand_id' => $brandId]);

    expect(Affiliate::query()->where('brand_key', $brandId)->count())->toBe(1);
});

it('TC-325-08: 無い銘柄を指すアフィリエイトは入れられない', function () {
    $brand = Brand::factory()->create();

    expect(fn () => Affiliate::factory()->create(['category_id' => $brand->category_id, 'brand_id' => $brand->id + 1]))
        ->toThrow(QueryException::class)
        ->and(Affiliate::query()->count())->toBe(0);
});

it('TC-325-09: Affiliate::brand() は、銘柄の行では銘柄を、ノードの行では null を返す', function () {
    $brand = Brand::factory()->create();
    $brandRow = Affiliate::factory()->create(['category_id' => $brand->category_id, 'brand_id' => $brand->id]);
    $nodeRow = Affiliate::factory()->create(['category_id' => $brand->category_id]);

    expect(Affiliate::findOrFail($brandRow->id)->brand?->is($brand))->toBeTrue()
        ->and(Affiliate::findOrFail($nodeRow->id)->brand)->toBeNull();
});
