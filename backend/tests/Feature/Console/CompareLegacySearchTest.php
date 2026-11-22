<?php

use App\Models\LegacyIdMap;
use App\Services\LegacySearchCompare\ExpectedDiff;
use App\Services\LegacySearchCompare\SearchCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\TestCase;

// legacy:compare-search(旧と新の条件検索の結果の突き合わせ。#169)。LegacyFixture の旧データ(と、足した旧の行)を
// legacy:import で移してから流す。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/**
 * LegacyFixture を入れ、$extra の旧の行を足してから、すべて移す。
 *
 * @param  array<string, list<array<string, mixed>>>  $extra  旧の表 => 足す行
 */
function importLegacyForCompare(TestCase $test, array $extra = []): void
{
    LegacyFixture::load();
    foreach ($extra as $table => $rows) {
        DB::connection('legacy')->table($table)->insert($rows);
    }
    Storage::fake('public');
    LegacyImportCommand::run($test, [])->assertExitCode(0)->run();
}

/**
 * 条件の組を一時ファイルに書いて legacy:compare-search を流し、終了コードと出力を返す。null なら既定のファイルを使う。
 *
 * @param  list<array<string, mixed>>|null  $cases
 * @return array{int, string}
 */
function runCompareSearch(?array $cases = null): array
{
    $options = [];
    if ($cases !== null) {
        $path = tempnam(sys_get_temp_dir(), 'search-cases');
        file_put_contents($path, json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $options['--cases'] = $path;
    }
    $exitCode = Artisan::call('legacy:compare-search', $options);
    if (isset($path)) {
        unlink($path);
    }

    return [$exitCode, Artisan::output()];
}

/**
 * 条件の組を 1 つ作る。
 *
 * @param  list<int>|array<string, mixed>  $categories
 * @param  list<int>|string  $middles
 * @return array<string, mixed>
 */
function compareCase(string $id, string $mode, array $categories, array|string $middles, bool $easy = false): array
{
    return ['id' => $id, 'mode' => $mode, 'categories' => $categories, 'middles' => $middles, 'methods' => 'all', 'easy' => $easy];
}

/**
 * 旧の材料の行(t_recipies)を 1 行作る。
 *
 * @return array<string, mixed>
 */
function compareRecipe(int $cocktailId, int $category, ?int $middle, bool $isOptional = false): array
{
    return [
        'cocktail_id' => $cocktailId, 'category' => $category, 'middle_category' => $middle,
        'brand' => null, 'amount' => '1tsp', 'is_optional' => $isOptional ? 1 : 0,
    ];
}

/**
 * 旧のカクテル(t_cocktails)を 1 行作る。
 *
 * @return array<string, mixed>
 */
function compareCocktail(int $id, string $name, int $recipeCount): array
{
    return [
        'id' => $id, 'name' => $name, 'name_search' => $name, 'name_eng' => null, 'base' => 1, 'volume' => 10.0,
        'method' => 1, 'ice' => 1, 'recipe_count' => $recipeCount, 'is_draft' => 0,
    ];
}

/**
 * 種別「その他」(5)と、そのカテゴリ 6・子のミドル 4 を足す旧の行。
 *
 * @return array<string, list<array<string, mixed>>>
 */
function compareOtherKindRows(): array
{
    return [
        'm_category_kinds' => [['id' => 5, 'name' => 'その他', 'name_eng' => 'Others', 'description' => '']],
        'm_category' => [['id' => 6, 'kind' => 5, 'name' => 'ハーブ', 'name_search' => 'ハーブ', 'name_eng' => 'Herb']],
        'm_middle' => [[
            'id' => 4, 'parent' => 6, 'name' => 'ミント', 'name_search' => 'ミント', 'name_eng' => 'Mint',
            'description' => '', 'is_like' => 0,
        ]],
    ];
}

it('TC-169-01: LegacyFixture の旧データを移した結果に search-cases.json の組をすべて流すと、説明できない差分なしで 0 で終わる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    [$exitCode, $output] = runCompareSearch();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('説明できない差分なし')
        ->and($output)->toContain('or-root-all')
        ->and($output)->not->toContain('説明できない差分 ');
});

it('TC-169-02: search-cases.json は 30 組以上で、3 つの方式それぞれに、根に近いノード・葉・その他・任意・技法・かんたん検索の組がある', function () {
    $raw = json_decode((string) file_get_contents(base_path('tests/Fixtures/search-cases.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($raw)->toBeArray();
    /** @var list<mixed> $raw */
    $cases = array_map(fn (mixed $item, int $index) => SearchCase::fromArray($item, $index), $raw, array_keys($raw));

    expect(count($cases))->toBeGreaterThanOrEqual(30);
    foreach (SearchCase::MODES as $mode) {
        $tags = array_merge(...array_map(
            fn (SearchCase $case) => $case->tags,
            array_filter($cases, fn (SearchCase $case) => $case->mode === $mode),
        ));
        expect(array_values(array_unique($tags)))
            ->toContain('root', 'leaf', 'other', 'optional', 'method', 'easy');
    }
});

it('TC-169-03: 新しい DB の材料を 1 行消すと、その材料で当たっていたカクテルを説明できない差分に出して 1 で終わる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);
    $cocktailId = LegacyIdMap::newIdsByLegacyId('t_cocktails', 'cocktails')[1];
    // ジン・トニックの、トニックウォーター(旧のカテゴリ 4)の行
    DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->where('position', 2)->delete();

    [$exitCode, $output] = runCompareSearch([compareCase('or-tonic', 'or', [4], 'children')]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('説明できない差分がある(1 件)')
        ->and($output)->toContain("旧にあって新に無い: cocktails #{$cocktailId}(t_cocktails #1)");
});

it('TC-169-04: OR でカテゴリを選び子のミドルを 1 つも選ばないと新で増えるカクテルは、#161 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    // ラム(2)の材料はダークラム(3)を指定した行だけなので、旧は Middle=[] で当たらない。新はラムの配下も一致する
    [$exitCode, $output] = runCompareSearch([compareCase('or-rum-none', 'or', [2], 'none')]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#161 カテゴリを選び、その子のミドルを 1 つも選ばない(`or`・`and`): 1 件');
});

it('TC-169-05: ミドルの親とカテゴリが合わない旧の材料の行で旧だけに当たるカクテルは、#28 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    // ラム・トニックの「カテゴリ 1・ミドル 3」の行は、旧はジン(1)で当たり、新はミドル 3 の親のラムに移っている
    [$exitCode, $output] = runCompareSearch([compareCase('or-gin-dark-rum', 'or', [1], [3])]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#28 ミドルの親とカテゴリが合わない材料の行: 1 件');
});

it('TC-169-06: あるもの検索で ID がカクテルの件数を超えるカクテルは、#165 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this, [
        // カクテルは 4 件になり、旧は ID 1〜4 しか見ないので、ID 10 は出ない
        't_cocktails' => [compareCocktail(10, 'トニック', 1)],
        't_recipies' => [compareRecipe(10, 4, null)],
    ]);

    [$exitCode, $output] = runCompareSearch([compareCase('having-all', 'having', [], 'children')]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#165 あるもの検索で、ID がカクテルの件数を超えるカクテル: 1 件');
});

it('TC-169-07: あるもの検索で Middle が空の配列だと旧が 0 件になる差分は、#165 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    // 子のミドルの無いジン(1)・トニックウォーター(4)・ライムジュース(5)だけを選ぶ。新はジン・トニックが作れる
    [$exitCode, $output] = runCompareSearch([compareCase('having-empty-middle', 'having', [1, 4, 5], 'none')]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#165 あるもの検索で、`Middle` が空の配列: 1 件');
});

it('TC-169-08: あるもの検索で、ミドルを指定した「その他」の材料を持つカクテルが新で増えるのは、#161 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this, [
        ...compareOtherKindRows(),
        't_cocktails' => [compareCocktail(4, 'ジン・ミント', 1)],
        't_recipies' => [compareRecipe(4, 1, null), compareRecipe(4, 6, 4)],
    ]);

    // 「その他」を外して、ほかをすべて選ぶ。旧はミント(ミドル 4)を選んでいないので、ジン・ミントを作れない扱い
    [$exitCode, $output] = runCompareSearch([compareCase('having-other-excluded', 'having', ['not_kind' => [5]], 'children')]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#161 あるもの検索の、ミドルを指定した「その他」の材料: 1 件');
});

it('TC-169-09: あるもの検索で、カテゴリを選び子のミドルを 1 つも選ばないと新で増えるカクテルは、#282 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    // ラム(2)の子のダークラム(3)を選ばず、ワイン(3)の赤ワイン(1)は選ぶ。新はラムを選んだ条件に直り、ラム・トニックが作れる
    [$exitCode, $output] = runCompareSearch([compareCase('having-rum-none', 'having', [1, 2, 3, 4], [1])]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#282 あるもの検索で、カテゴリを選び、その子のミドルを 1 つも選ばない: 1 件');
});

it('TC-169-10: かんたん検索で、旧の recipe_count と数え直した材料の数で出る・出ないが変わるカクテルは、#154 の節で説明できる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    // ラム・トニックは旧の recipe_count が 3 で、数え直すと 4(LegacyFixture の異常)
    [$exitCode, $output] = runCompareSearch([compareCase('or-all-easy', 'or', [], 'children', easy: true)]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('#154 かんたん検索の材料の数: 1 件');
});

it('TC-169-11: 旧 DB に当たるカテゴリの無い選び方の組は、流さずに理由を出し、失敗にしない', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    [$exitCode, $output] = runCompareSearch([compareCase('or-other', 'or', ['kind' => [5]], 'children')]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('流さない: 選び方に当たるカテゴリが旧 DB に無い')
        ->and($output)->toContain('流さなかった組 1');
});

it('TC-169-12: 条件の組のファイルが無い・形が合わないと、理由を出して 1 で終わる', function (mixed $cases, string $message) {
    /** @var TestCase $this */
    importLegacyForCompare($this);

    if ($cases === null) {
        $exitCode = Artisan::call('legacy:compare-search', ['--cases' => sys_get_temp_dir().'/no-such-search-cases.json']);
        $output = Artisan::output();
    } else {
        /** @var list<array<string, mixed>> $cases */
        [$exitCode, $output] = runCompareSearch($cases);
    }

    expect($exitCode)->toBe(1)->and($output)->toContain($message);
})->with([
    'ファイルが無い' => [null, 'ファイルが無い'],
    '方式が無い' => [[['id' => 'x', 'mode' => 'xor', 'categories' => [1], 'middles' => 'none']], '組 x: mode は'],
    'id が重なる' => [[compareCase('x', 'or', [1], 'none'), compareCase('x', 'or', [2], 'none')], '組の id が重なっている: x'],
    'ミドルの選び方が無い' => [[compareCase('x', 'or', [1], 'all')], '組 x: middles の選び方は'],
]);

it('TC-169-13: 期待する差分の節は、どれも docs/search-expected-diffs.md の、決めた issue の節の下に見出しとしてある', function (ExpectedDiff $diff) {
    $doc = (string) file_get_contents(base_path('../docs/search-expected-diffs.md'));

    $start = strpos($doc, "\n## [#{$diff->issue()}]");
    expect($start)->not->toBeFalse();
    $section = substr($doc, (int) $start + 1);
    $next = strpos($section, "\n## ");
    $section = $next === false ? $section : substr($section, 0, $next);

    expect($section)->toContain("\n### {$diff->heading()}\n");
})->with(ExpectedDiff::cases());

it('TC-169-14: 種別「その他」と、ID がカクテルの件数を超えるカクテルを足した旧データでも、search-cases.json の組をすべて流して 0 で終わる', function () {
    /** @var TestCase $this */
    importLegacyForCompare($this, [
        ...compareOtherKindRows(),
        't_cocktails' => [compareCocktail(4, 'ジン・ミント', 1), compareCocktail(10, 'トニック', 1)],
        't_recipies' => [
            compareRecipe(4, 1, null), compareRecipe(4, 6, 4), compareRecipe(4, 6, null, isOptional: true),
            compareRecipe(10, 4, null),
        ],
    ]);

    [$exitCode, $output] = runCompareSearch();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('説明できない差分なし')
        ->and($output)->toContain('流さなかった組 0)')
        ->and($output)->toContain('#161 あるもの検索の、ミドルを指定した「その他」の材料')
        ->and($output)->toContain('#165 あるもの検索で、ID がカクテルの件数を超えるカクテル');
});
