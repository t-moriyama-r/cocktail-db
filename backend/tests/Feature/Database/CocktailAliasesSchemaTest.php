<?php

use App\Models\Cocktail;
use App\Models\CocktailAlias;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchemaShape;

// カクテルの別名の表(cocktail_aliases。#82)の形を docs/schema.md と突き合わせる。

uses(RefreshDatabase::class)->group('db');

it('TC-82-01: cocktail_aliases の列・索引・unique・外部キー(カクテルを消すと消える)が設計どおり', function () {
    expect(SchemaShape::columns('cocktail_aliases'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'cocktail_id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_search' => ['varchar(255)', false, null],
    ])
        ->and(SchemaShape::indexes('cocktail_aliases'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'cocktail_aliases_name_search_index' => ['name_search'],
            'cocktail_aliases_cocktail_id_name_search_unique' => ['cocktail_id', 'name_search'],
        ])
        ->and(SchemaShape::foreignKeys('cocktail_aliases'))->toBe([
            ['cocktail_id', 'cocktails', 'id', 'cascade'],
        ]);
});

it('TC-82-02: 同じカクテルに、検索用の名前が同じ別名を 2 つ入れられない(違うカクテルなら入る)', function () {
    $cocktail = Cocktail::factory()->create();
    CocktailAlias::factory()->named('ピーチ・ウーロン')->create(['cocktail_id' => $cocktail->id]);
    CocktailAlias::factory()->named('ピーチ・ウーロン')->create();

    // 中黒の有無は検索用の名前では同じになる
    expect(fn () => CocktailAlias::factory()->named('ピーチウーロン')->create(['cocktail_id' => $cocktail->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('TC-82-10: カクテルを消すと、その別名も消える(ほかのカクテルの別名は残る)', function () {
    $deleted = CocktailAlias::factory()->create();
    $kept = CocktailAlias::factory()->create();

    $deleted->cocktail()->firstOrFail()->delete();

    expect(CocktailAlias::query()->pluck('id')->all())->toBe([$kept->id]);
});
