<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use App\Services\Recipe\StrengthCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\Support\LegacyNodeMap;
use Tests\TestCase;

// legacy:import で、ミドル 58(グリーン)の有名な銘柄 138(グリーン バナナ)を、親のカテゴリ 94 の直下からグリーンの下へ移す
// (#752。docs/migration-anomalies.md の 15)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/** 旧のカテゴリ 94(バナナ・リキュール) */
const GREEN_BANANA_CATEGORY = 94;

/** 旧のミドル 58(グリーン。親はカテゴリ 94) */
const GREEN_BANANA_MIDDLE = 58;

/** 旧の銘柄 138(グリーン バナナ。カテゴリ 94 の直下) */
const GREEN_BANANA_BRAND = 138;

/**
 * LegacyFixture に、本番の旧データの形(値は作ったもの)で、カテゴリ 94・ミドル 58・銘柄 138 と、それを使うカクテルを足す。
 *
 * - カクテル 10: カテゴリ 94 と銘柄 138 の材料(#751 の規則で、銘柄の所属が材料のノードの子孫になる行)と、ジン
 * - カクテル 11: ミドル 58 を使い銘柄を決めていない材料だけ(度数はグリーンの有名な銘柄で計算する)
 */
function addLegacyGreenBanana(): void
{
    $legacy = DB::connection('legacy');
    $legacy->table('m_category')->insert([
        'id' => GREEN_BANANA_CATEGORY, 'kind' => 1, 'name' => 'バナナ・リキュール', 'name_search' => 'バナナ・リキュール',
        'name_eng' => 'Banana Liqueur', 'famous_brand_id' => GREEN_BANANA_BRAND,
    ]);
    $legacy->table('m_middle')->insert([
        'id' => GREEN_BANANA_MIDDLE, 'parent' => GREEN_BANANA_CATEGORY, 'name' => 'グリーン', 'name_search' => 'グリーン',
        'name_eng' => 'Green', 'description' => '', 'is_like' => 0, 'famous_brand_id' => GREEN_BANANA_BRAND,
    ]);
    $legacy->table('m_brand')->insert([
        'id' => GREEN_BANANA_BRAND, 'category' => GREEN_BANANA_CATEGORY, 'middle_category' => null,
        'name' => 'グリーン バナナ', 'name_search' => 'グリーン バナナ', 'name_eng' => 'Green Banana', 'volume' => 14,
    ]);
    $cocktail = fn (int $id, string $name, int $recipeCount): array => [
        'id' => $id, 'name' => $name, 'name_search' => $name, 'name_eng' => null, 'base' => GREEN_BANANA_CATEGORY,
        'volume' => 14, 'method' => 1, 'ice' => 1, 'recipe_count' => $recipeCount, 'is_draft' => 0,
    ];
    $legacy->table('t_cocktails')->insert([$cocktail(10, 'バナナ・ジン', 2), $cocktail(11, 'グリーン・ショット', 1)]);
    $recipe = fn (int $cocktailId, int $category, ?int $middle, ?int $brand, string $amount): array => [
        'cocktail_id' => $cocktailId, 'category' => $category, 'middle_category' => $middle,
        'brand' => $brand, 'amount' => $amount, 'is_optional' => 0,
    ];
    $legacy->table('t_recipies')->insert([
        $recipe(10, GREEN_BANANA_CATEGORY, null, GREEN_BANANA_BRAND, '30ml'),
        $recipe(10, 1, null, 1, '30ml'),
        $recipe(11, GREEN_BANANA_CATEGORY, GREEN_BANANA_MIDDLE, null, '30ml'),
    ]);
}

/**
 * 根から銘柄の所属ノードまでの名前。
 *
 * @return list<string>
 */
function greenBananaBrandPath(int $brandId): array
{
    $node = Brand::query()->findOrFail($brandId)->category()->firstOrFail();

    return array_values(app(CategoryTree::class)->path($node)->map(fn (Category $c): string => $c->name)->all());
}

/**
 * コマンドを流し、終了コードと出力を返す。
 *
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runGreenBananaCommand(string $command, array $parameters = []): array
{
    $exitCode = Artisan::call($command, $parameters);

    return [$exitCode, Artisan::output()];
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-752-01: 銘柄 138 の所属を、カテゴリ 94 の直下ではなくミドル 58(グリーン)のノードにし、移した数 1 を出す', function () {
    addLegacyGreenBanana();
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();

    LegacyImportCommand::run($this, ['--only' => 'brands'])
        ->expectsOutput('brands: 5')
        ->expectsOutput('ミドルの下へ移した銘柄: 1')
        ->assertExitCode(0)
        ->run();

    expect(Brand::query()->findOrFail(GREEN_BANANA_BRAND)->category_id)->toBe(LegacyNodeMap::id('m_middle', GREEN_BANANA_MIDDLE))
        ->and(greenBananaBrandPath(GREEN_BANANA_BRAND))->toBe(['蒸留酒', 'バナナ・リキュール', 'グリーン']);
});

it('TC-752-02: グリーンとバナナ・リキュールの有名な銘柄は、どちらも銘柄 138 のまま', function () {
    addLegacyGreenBanana();
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();

    LegacyImportCommand::run($this, ['--only' => 'brands'])->assertExitCode(0)->run();

    expect(LegacyNodeMap::node('m_middle', GREEN_BANANA_MIDDLE)->famous_brand_id)->toBe(GREEN_BANANA_BRAND)
        ->and(LegacyNodeMap::node('m_category', GREEN_BANANA_CATEGORY)->famous_brand_id)->toBe(GREEN_BANANA_BRAND);
});

it('TC-752-03: すべて移した後、data:verify に「有名な銘柄が部分木の外」も「材料のノードと銘柄の所属」も出ず、0 で終わる', function () {
    addLegacyGreenBanana();
    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    [$exitCode, $output] = runGreenBananaCommand('data:verify');

    expect($output)->not->toContain('有名な銘柄が部分木の外')
        ->and($output)->not->toContain('材料のノードと銘柄の所属')
        ->and($exitCode)->toBe(0);
});

it('TC-752-04: 銘柄 138 を使う材料の行のノードは、バナナ・リキュールのまま動かさない', function () {
    addLegacyGreenBanana();
    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    $cocktailId = LegacyIdMap::newIdsByLegacyId('t_cocktails', 'cocktails')[10];
    $nodeId = CocktailIngredient::query()->where('cocktail_id', $cocktailId)->where('brand_id', GREEN_BANANA_BRAND)->sole()->category_id;

    expect($nodeId)->toBe(LegacyNodeMap::id('m_category', GREEN_BANANA_CATEGORY));
});

it('TC-752-05: グリーンを使い銘柄を決めていない材料の度数は、移した後も銘柄 138 の度数(14%)で計算する', function () {
    addLegacyGreenBanana();
    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    $cocktailId = LegacyIdMap::newIdsByLegacyId('t_cocktails', 'cocktails')[11];

    expect(app(StrengthCalculator::class)->forCocktail(Cocktail::query()->findOrFail($cocktailId)))->toBe(14.0);
});

it('TC-752-06: すべて移した後、legacy:verify が問題なしで 0 で終わる', function () {
    addLegacyGreenBanana();
    Storage::fake('public');
    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    [$exitCode, $output] = runGreenBananaCommand('legacy:verify');

    expect($output)->toContain('問題なし')
        ->and($exitCode)->toBe(0);
});

it('TC-752-07: 2 回流しても、銘柄 138 はグリーンの下で、移した数は 1', function () {
    addLegacyGreenBanana();
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();
    LegacyImportCommand::run($this, ['--only' => 'brands'])->assertExitCode(0)->run();

    LegacyImportCommand::run($this, ['--only' => 'brands'])
        ->expectsOutput('ミドルの下へ移した銘柄: 1')
        ->assertExitCode(0)
        ->run();

    expect(Brand::query()->findOrFail(GREEN_BANANA_BRAND)->category_id)->toBe(LegacyNodeMap::id('m_middle', GREEN_BANANA_MIDDLE))
        ->and(DB::table('brands')->count())->toBe(5);
});

it('TC-752-08: 旧の銘柄 138 が移行までに直されていたら、旧の所属のまま移し、移した数 0 を出す(:dataset)', function (int $category, ?int $middle, string $node, int $legacyId) {
    addLegacyGreenBanana();
    DB::connection('legacy')->table('m_brand')->where('id', GREEN_BANANA_BRAND)
        ->update(['category' => $category, 'middle_category' => $middle]);
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();

    LegacyImportCommand::run($this, ['--only' => 'brands'])
        ->expectsOutput('ミドルの下へ移した銘柄: 0')
        ->assertExitCode(0)
        ->run();

    expect(Brand::query()->findOrFail(GREEN_BANANA_BRAND)->category_id)->toBe(LegacyNodeMap::id($node, $legacyId));
})->with([
    '旧でミドル 58 に付け直されていた' => [GREEN_BANANA_CATEGORY, GREEN_BANANA_MIDDLE, 'm_middle', GREEN_BANANA_MIDDLE],
    '旧で別のミドル(3)に付け直されていた' => [2, 3, 'm_middle', 3],
    '旧で別のカテゴリ(1)の直下に移されていた' => [1, null, 'm_category', 1],
]);

it('TC-752-09: 旧に銘柄 138 もミドル 58 も無ければ、ほかの銘柄の所属を変えず、移した数 0 を出す', function () {
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();

    LegacyImportCommand::run($this, ['--only' => 'brands'])
        ->expectsOutput('brands: 4')
        ->expectsOutput('ミドルの下へ移した銘柄: 0')
        ->assertExitCode(0)
        ->run();

    expect(Brand::query()->findOrFail(1)->category_id)->toBe(LegacyNodeMap::id('m_category', 1))
        ->and(Brand::query()->findOrFail(2)->category_id)->toBe(LegacyNodeMap::id('m_middle', 3));
});
