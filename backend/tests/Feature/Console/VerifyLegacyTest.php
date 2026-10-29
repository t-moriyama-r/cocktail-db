<?php

use App\Models\LegacyIdMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\TestCase;

// legacy:verify(旧 DB から移した結果が旧と合っているか。#35)。LegacyFixture の旧データをすべて移してから、
// 新しい DB を 1 か所ずつ壊して失敗することを見る。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    LegacyFixture::load();
    Storage::fake('public');
    /** @var TestCase $this */
    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();
});

/**
 * legacy:verify を流し、終了コードと出力を返す。一覧の中身を部分一致で確かめるときに使う。
 *
 * @return array{int, string}
 */
function runVerifyLegacy(): array
{
    $exitCode = Artisan::call('legacy:verify');

    return [$exitCode, Artisan::output()];
}

/**
 * 旧のカクテルを移した先の、新しいカクテルの ID。
 */
function verifyLegacyCocktailId(int $legacyId): int
{
    return LegacyIdMap::newIdsByLegacyId('t_cocktails', 'cocktails')[$legacyId]
        ?? throw new RuntimeException("t_cocktails #{$legacyId} の対応が無い");
}

it('TC-35-01: LegacyFixture の旧データをすべて移した結果は、問題なしで 0 で終わる', function () {
    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('問題なし')
        ->and($output)->not->toContain('移行の結果が旧と合わない');
});

it('TC-35-02: 新しい DB の材料を 1 行消すと、件数と、そのカクテルの材料の組の食い違いを出して 1 で終わる', function () {
    DB::table('cocktail_ingredients')
        ->where('cocktail_id', verifyLegacyCocktailId(1))
        ->where('position', 2)
        ->delete();

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('旧(t_recipies。消えたカクテルの行と、同じ行の 2 行目以降を除く)は 7 件、新は 6 件')
        ->and($output)->toContain('t_cocktails #1')
        ->and($output)->toContain('旧: m_category:1 m_brand:1 45ml、m_category:4 銘柄なし 適量、m_category:5 銘柄なし 1tsp 任意 / 新: m_category:1 m_brand:1 45ml、m_category:5 銘柄なし 1tsp 任意');
});

it('TC-35-03: 材料の並びが旧と違うと、件数が合っていても失敗する', function () {
    $cocktailId = verifyLegacyCocktailId(1);
    // position の一意制約に当たらないよう、いったん空いている番号に逃がしてから入れ替える
    DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->where('position', 1)->update(['position' => 100]);
    DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->where('position', 2)->update(['position' => 1]);
    DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->where('position', 100)->update(['position' => 2]);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('材料の組と並び')
        ->and($output)->toContain('新: m_category:4 銘柄なし 適量、m_category:1 m_brand:1 45ml')
        ->and($output)->not->toContain('旧(t_recipies');
});

it('TC-35-04: 材料の量・銘柄・任意が旧と違うと失敗する', function (string $column, mixed $value, string $expectedNew) {
    DB::table('cocktail_ingredients')
        ->where('cocktail_id', verifyLegacyCocktailId(1))
        ->where('position', 1)
        ->update([$column => $value]);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("新: {$expectedNew}、m_category:4 銘柄なし 適量");
})->with([
    '量' => ['amount', '30ml', 'm_category:1 m_brand:1 30ml'],
    '銘柄' => ['brand_id', null, 'm_category:1 銘柄なし 45ml'],
    '任意' => ['is_optional', true, 'm_category:1 m_brand:1 45ml 任意'],
]);

it('TC-35-05: 旧で材料・銘柄・量まで同じ 2 行は 1 行として比べ、新に 2 行目を足すと失敗する', function () {
    $cocktailId = verifyLegacyCocktailId(2);
    $duplicated = DB::table('cocktail_ingredients')
        ->where('cocktail_id', $cocktailId)
        ->where('amount', '適量')
        ->sole();
    $last = DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->max('position');
    DB::table('cocktail_ingredients')->insert([
        'cocktail_id' => $cocktailId,
        'position' => (is_numeric($last) ? (int) $last : 0) + 1,
        'category_id' => $duplicated->category_id,
        'brand_id' => $duplicated->brand_id,
        'amount' => $duplicated->amount,
        'is_optional' => $duplicated->is_optional,
    ]);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('t_cocktails #2')
        ->and($output)->toContain('は 7 件、新は 8 件');
});

it('TC-35-06: legacy_id_maps に旧の行の対応が無いと、旧の表と ID を挙げて失敗する', function () {
    LegacyIdMap::query()->where('legacy_table', 'm_category_kinds')->where('legacy_id', 2)->delete();

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('m_category_kinds #2')
        ->and($output)->toContain('categories への対応が無い');
});

it('TC-35-07: 対応の先の行が新しい DB に無いと失敗する', function () {
    $cocktailId = verifyLegacyCocktailId(3);
    DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->delete();
    DB::table('cocktails')->where('id', $cocktailId)->delete();

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("対応の先の cocktails #{$cocktailId} が無い")
        ->and($output)->toContain('旧(t_cocktails)は 3 件、新は 2 件');
});

it('TC-35-08: 旧から移す表に、旧に無い行が増えていると件数の食い違いで失敗する', function () {
    DB::table('skip_words')->insert(['name' => '旧に無い除外語']);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('skip_words')
        ->and($output)->toContain('旧(skip_db)は 1 件、新は 2 件');
});

it('TC-35-09: tree:verify が失敗すると失敗する', function () {
    // 閉包表を直接壊すのは、tree:verify が失敗する状態を作るためだけ(CategoryTree では壊れた木を作れない)
    $leaf = LegacyIdMap::newIdsByLegacyId('m_middle', 'categories')[1];
    DB::table('category_closures')->where('descendant_id', $leaf)->where('depth', 2)->delete();

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('tree:verify')
        ->and($output)->toContain('失敗した(上の出力を見る)');
});

it('TC-35-10: data:verify が失敗すると失敗する', function () {
    DB::table('cocktails')->where('id', verifyLegacyCocktailId(1))->update(['recipe_count' => 99]);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('材料の数(recipe_count)')
        ->and($output)->toContain('data:verify');
});

it('TC-35-11: 説明文の参照が実在しないカクテル・ノード・銘柄・用語を指すと、data:verify を通して失敗する', function (string $reference) {
    DB::table('cocktails')->where('id', verifyLegacyCocktailId(1))->update(['description' => "前{$reference}後"]);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('説明文の参照の指す先が無い')
        ->and($output)->toContain($reference);
})->with(['@c999@', '@n999@', '@b999@', '@d999@']);

it('TC-35-12: 画像の列が指すファイルが無いと、表と ID とファイル名を挙げて失敗する', function () {
    $brandId = LegacyIdMap::newIdsByLegacyId('m_brand', 'brands')[1];
    DB::table('brands')->where('id', $brandId)->update(['image' => 'gone.png']);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("銘柄 #{$brandId}")
        ->and($output)->toContain('image の gone.png が無い');
});

it('TC-35-13: 画像の列が指すファイルがある・外部の URL・空文字なら、画像では失敗しない', function () {
    Storage::disk('public')->put('here.jpeg', 'x');
    DB::table('cocktails')->where('id', verifyLegacyCocktailId(1))->update(['image' => 'here.jpeg']);
    DB::table('cocktails')->where('id', verifyLegacyCocktailId(2))->update(['image' => 'https://example.com/a.jpg']);
    DB::table('cocktails')->where('id', verifyLegacyCocktailId(3))->update(['image' => '']);

    [$exitCode, $output] = runVerifyLegacy();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('問題なし');
});
