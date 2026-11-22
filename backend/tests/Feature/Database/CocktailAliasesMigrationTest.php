<?php

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\RecipeSignature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 別名の表と recipe_signature の列のマイグレーション(#82)を、migrate・migrate:rollback で通して確かめる。
// DDL は MariaDB で暗黙にコミットされるので RefreshDatabase は使わず、テストのたびに作り直して空の DB で終える
// (ほかのテストファイルの RefreshDatabase は、一度マイグレーションした後は作り直さない)。

uses()->group('db');

const COCKTAIL_ALIASES_MIGRATION = '2026_10_29_000000_create_cocktail_aliases_table';

/**
 * #82 のマイグレーションの手前まで戻す。
 */
function rollbackCocktailAliasesMigrations(): void
{
    $steps = DB::table('migrations')->where('migration', '>=', COCKTAIL_ALIASES_MIGRATION)->count();
    Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);
}

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('TC-82-11: #82 のマイグレーションを戻すと別名の表と recipe_signature の列が消え、もう一度 migrate すると戻る', function () {
    rollbackCocktailAliasesMigrations();

    expect(Schema::hasTable('cocktail_aliases'))->toBeFalse()
        ->and(Schema::hasColumn('cocktails', 'recipe_signature'))->toBeFalse()
        ->and(Schema::hasTable('cocktails'))->toBeTrue();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('cocktail_aliases'))->toBeTrue()
        ->and(Schema::hasColumn('cocktails', 'recipe_signature'))->toBeTrue();
});

it('TC-82-12: 移行を済ませた DB に列を足すと、今ある材料から recipe_signature が入る(材料の無いカクテルは null)', function () {
    rollbackCocktailAliasesMigrations();
    $gin = Category::factory()->create();
    $withIngredients = Cocktail::factory()->create();
    CocktailIngredient::factory()->create(['cocktail_id' => $withIngredients->id, 'position' => 1, 'category_id' => $gin->id]);
    $withoutIngredients = Cocktail::factory()->create();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

    expect(DB::table('cocktails')->orderBy('id')->pluck('recipe_signature', 'id')->all())->toBe([
        $withIngredients->id => RecipeSignature::of([['category_id' => $gin->id, 'brand_id' => null, 'amount' => '30ml', 'is_optional' => false]]),
        $withoutIngredients->id => null,
    ]);
});
