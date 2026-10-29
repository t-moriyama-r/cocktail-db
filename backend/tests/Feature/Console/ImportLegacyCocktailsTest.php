<?php

use App\Models\Category;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\TestCase;

// legacy:import --only=cocktails(カクテルと材料を移す。#31)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/**
 * カクテルが指す技法・氷・カテゴリ木・銘柄を移してから、カクテルを移す。
 */
function importLegacyCocktails(TestCase $test): PendingCommand
{
    foreach (['misc', 'categories', 'brands'] as $part) {
        LegacyImportCommand::run($test, ['--only' => $part])->assertExitCode(0)->run();
    }

    return LegacyImportCommand::run($test, ['--only' => 'cocktails']);
}

/**
 * クエリビルダーで読んだ列の値(数)を int にする。
 */
function cocktailColumnInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : throw new RuntimeException('数であるはずの列の値が数でない');
}

/**
 * 移した先のノードの ID から、旧の表と ID(「m_middle:3」の形)を legacy_id_maps で引く。
 */
function legacyNodeOf(int $nodeId): string
{
    $map = LegacyIdMap::query()->where('new_table', 'categories')->where('new_id', $nodeId)->sole();

    return "{$map->legacy_table}:{$map->legacy_id}";
}

/**
 * 移したカクテルの材料を、並び順のまま、ノードを旧の表と ID に戻して返す。
 *
 * @return list<array{int, string, int|null, string, bool}> [position, 旧のノード, 銘柄, 量, 任意]
 */
function legacyIngredientsOf(int $cocktailId): array
{
    $ingredients = [];
    foreach (DB::table('cocktail_ingredients')->where('cocktail_id', $cocktailId)->orderBy('position')->get() as $row) {
        $ingredients[] = [
            cocktailColumnInt($row->position),
            legacyNodeOf(cocktailColumnInt($row->category_id)),
            $row->brand_id === null ? null : cocktailColumnInt($row->brand_id),
            is_string($row->amount) ? $row->amount : throw new RuntimeException('量が文字列でない'),
            (bool) $row->is_optional,
        ];
    }

    return $ingredients;
}

/**
 * 旧の材料の行を足す。足した順が、旧アプリが返していた並び順になる。
 */
function addLegacyRecipe(int $cocktailId, int $category, ?int $middle, ?int $brand, string $amount, bool $isOptional = false): void
{
    DB::connection('legacy')->table('t_recipies')->insert([
        'cocktail_id' => $cocktailId, 'category' => $category, 'middle_category' => $middle,
        'brand' => $brand, 'amount' => $amount, 'is_optional' => $isOptional ? 1 : 0,
    ]);
}

/**
 * カクテルの移行で書く表(カクテル・材料・ID の対応)を、比べられる形で返す。
 *
 * @return array<string, list<array<mixed>>>
 */
function importedCocktailSnapshot(): array
{
    $rows = function (string $table, string ...$orderBy): array {
        $query = DB::table($table);
        foreach ($orderBy as $column) {
            $query->orderBy($column);
        }
        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    };

    return [
        'cocktails' => $rows('cocktails', 'id'),
        'cocktail_ingredients' => $rows('cocktail_ingredients', 'cocktail_id', 'position'),
        'legacy_id_maps' => $rows('legacy_id_maps', 'legacy_table', 'legacy_id'),
    ];
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-31-01: カクテルと材料の件数を出力する(消えたカクテルの材料と、量まで同じ行を除く)', function () {
    // 材料の行は 10 行。カクテル 99 の 2 行(消えたカクテル)と、カクテル 2 の「適量」の 2 行目(量まで同じ)を除いて 7 行
    importLegacyCocktails($this)
        ->expectsOutput('cocktails:')
        ->expectsOutput('cocktails: 3')
        ->expectsOutput('cocktail_ingredients: 7')
        ->assertExitCode(0)
        ->run();

    expect(DB::table('cocktails')->count())->toBe(DB::connection('legacy')->table('t_cocktails')->count())->toBe(3)
        ->and(DB::table('cocktail_ingredients')->count())->toBe(7);
});

it('TC-31-02: カクテルの ID を旧 ID のまま入れ、legacy_id_maps に同じ値の対応を入れる', function () {
    importLegacyCocktails($this)->assertExitCode(0)->run();

    $maps = LegacyIdMap::query()->where('legacy_table', 't_cocktails')->orderBy('legacy_id')->get();
    expect(DB::table('cocktails')->orderBy('id')->pluck('id')->map(cocktailColumnInt(...))->all())->toBe([1, 2, 3])
        ->and($maps->map(fn (LegacyIdMap $map): array => [$map->legacy_id, $map->new_table, $map->new_id])->all())
        ->toBe([[1, 'cocktails', 1], [2, 'cocktails', 2], [3, 'cocktails', 3]]);
});

it('TC-31-03: カクテルの列の値を旧からそのまま移す', function () {
    DB::connection('legacy')->table('t_cocktails')->where('id', 2)->update([
        'description' => 'カクテルの説明', 'note' => 'メモ', 'sweet_degree' => -3, 'rate' => 4, 'glass' => 1, 'ice' => 1,
        'is_major' => 1, 'instagram' => 'https://www.instagram.com/p/example/',
        'youtube' => 'https://www.youtube.com/watch?v=example', 'cc_youtube_created_at' => '2020-05-06 07:08:09',
        'y_hour' => null, 'y_minute' => 2, 'y_second' => 30,
        'image' => 'sample.jpg', 'image_txt' => '画像の文', 'image_url' => 'https://example.com/',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect((array) DB::table('cocktails')->where('id', 2)->sole())->toMatchArray([
        'name' => 'ラム・トニック', 'name_search' => 'ラム・トニック', 'name_eng' => 'Rum and Tonic',
        'method_id' => 1, 'glass_dictionary_id' => 1, 'ice_id' => 1, 'volume' => '10.5',
        'sweet_degree' => -3, 'rate' => 4, 'description' => 'カクテルの説明', 'note' => 'メモ',
        'is_draft' => 0, 'is_major' => 1, 'instagram' => 'https://www.instagram.com/p/example/',
        'youtube' => 'https://www.youtube.com/watch?v=example', 'youtube_created_at' => '2020-05-06 07:08:09',
        'youtube_start_seconds' => 150,
        'image' => 'sample.jpg', 'image_txt' => '画像の文', 'image_url' => 'https://example.com/',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ])->and(DB::table('cocktails')->where('id', 3)->value('is_draft'))->toBe(1);
});

it('TC-31-04: ベースを、旧のカテゴリを移した先のノードに付け替える', function () {
    importLegacyCocktails($this)->assertExitCode(0)->run();

    $base = fn (int $id): int => cocktailColumnInt(DB::table('cocktails')->where('id', $id)->value('base_category_id'));
    $path = fn (int $id): array => array_values(app(CategoryTree::class)->path(Category::query()->findOrFail($base($id)))
        ->map(fn (Category $c): string => $c->name)->all());
    expect(legacyNodeOf($base(1)))->toBe('m_category:1')
        ->and(legacyNodeOf($base(2)))->toBe('m_category:2')
        ->and($path(1))->toBe(['蒸留酒', 'ジン'])
        ->and($path(2))->toBe(['蒸留酒', 'ラム']);
});

it('TC-31-05: 旧の float の度数を、計算し直さずに小数 1 桁の値にして移す(:dataset)', function (float $legacy, string $expected) {
    DB::connection('legacy')->table('t_cocktails')->where('id', 1)->update(['volume' => $legacy]);

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(DB::table('cocktails')->where('id', 1)->value('volume'))->toBe($expected);
})->with([
    '0' => [0.0, '0.0'],
    '2 進で表せない 1.7' => [1.7, '1.7'],
    '旧の最大 85.8' => [85.8, '85.8'],
]);

it('TC-31-06: 度数が小数 1 桁に収まらないと、例外で止まり、カクテルも材料も入れない', function () {
    DB::connection('legacy')->table('t_cocktails')->where('id', 3)->update(['volume' => 12.34]);

    expect(fn () => importLegacyCocktails($this)->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('cocktails')->count())->toBe(0)
        ->and(DB::table('cocktail_ingredients')->count())->toBe(0)
        ->and(LegacyIdMap::query()->where('legacy_table', 't_cocktails')->count())->toBe(0);
});

it('TC-31-07: グラス・氷が 0 か null なら null にする(:dataset)', function (?int $legacy) {
    DB::connection('legacy')->table('t_cocktails')->where('id', 1)->update(['glass' => $legacy, 'ice' => $legacy]);

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect((array) DB::table('cocktails')->where('id', 1)->first(['glass_dictionary_id', 'ice_id']))
        ->toBe(['glass_dictionary_id' => null, 'ice_id' => null]);
})->with([
    '0' => [0],
    'null' => [null],
]);

it('TC-31-08: 材料を、旧の行を入れた順(どの列の順でもない)に並べ、カクテルごとに 1 から振る', function () {
    // カクテル 3 の行を、カテゴリ・ミドル・量のどの順でもないように入れ、間にカクテル 1 の行を挟む
    addLegacyRecipe(3, 4, null, null, '適量');
    addLegacyRecipe(3, 1, null, 1, '45ml');
    addLegacyRecipe(1, 2, 3, null, '5ml');
    addLegacyRecipe(3, 3, 1, null, '1dash');
    addLegacyRecipe(3, 2, null, null, '10ml');

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(legacyIngredientsOf(3))->toBe([
        [1, 'm_category:4', null, '適量', false],
        [2, 'm_category:1', 1, '45ml', false],
        [3, 'm_middle:1', null, '1dash', false],
        [4, 'm_category:2', null, '10ml', false],
    ])->and(legacyIngredientsOf(1))->toBe([
        [1, 'm_category:1', 1, '45ml', false],
        [2, 'm_category:4', null, '適量', false],
        [3, 'm_category:5', null, '1tsp', true],
        [4, 'm_middle:3', null, '5ml', false],
    ]);
});

it('TC-31-09: ミドルの親とカテゴリが合わない材料の行は、カテゴリの列を見ずにミドルのノードに付ける(#28 の 3)', function () {
    // LegacyFixture のカクテル 2 の最後の行は、カテゴリ 1(ジン)・ミドル 3(親はカテゴリ 2 のラム)
    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(legacyIngredientsOf(2)[3])->toBe([4, 'm_middle:3', null, '10ml', false]);
});

it('TC-31-10: カテゴリしか指定していない行の銘柄がミドルの下にあっても、ノードはカテゴリのまま(#28 の 11)', function () {
    // 銘柄 2 はミドル 3(ダークラム)に属する
    addLegacyRecipe(3, 2, null, 2, '45ml');

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(legacyIngredientsOf(3))->toBe([[1, 'm_category:2', 2, '45ml', false]]);
});

it('TC-31-11: カクテルが消えた後に残った材料の行は移さない(#28 の 1)', function () {
    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(DB::table('cocktail_ingredients')->where('cocktail_id', 99)->count())->toBe(0)
        ->and(DB::table('cocktails')->where('id', 99)->exists())->toBeFalse();
});

it('TC-31-12: 材料・銘柄・量まで同じ行は 1 行にまとめ、量が違えば両方移す(#28 の 2)', function () {
    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(legacyIngredientsOf(2))->toBe([
        [1, 'm_middle:3', 2, '45ml', false],
        [2, 'm_middle:3', 2, '30ml', false],
        [3, 'm_category:4', null, '適量', false],
        [4, 'm_middle:3', null, '10ml', false],
    ]);
});

it('TC-31-13: 任意かどうかだけが違う行は、まとめずに両方移す', function () {
    addLegacyRecipe(3, 1, null, null, '10ml');
    addLegacyRecipe(3, 1, null, null, '10ml', isOptional: true);

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(legacyIngredientsOf(3))->toBe([
        [1, 'm_category:1', null, '10ml', false],
        [2, 'm_category:1', null, '10ml', true],
    ]);
});

it('TC-31-14: recipe_count を旧の値でなく、移した材料から数え直す。更新日時は旧の値のまま', function () {
    $legacy = DB::connection('legacy');
    // 役割が「その他」の根(旧の種別 5)の材料は数えない
    $legacy->table('m_category_kinds')->insert(['id' => 5, 'name' => 'その他', 'name_eng' => 'Other', 'description' => '']);
    $legacy->table('m_category')->insert(['id' => 6, 'kind' => 5, 'name' => '飾り', 'name_search' => '飾り', 'name_eng' => 'Garnish']);
    addLegacyRecipe(1, 6, null, null, '1個');
    $legacy->table('t_cocktails')->where('id', 2)->update(['updated_at' => '2021-02-03 04:05:06']);

    importLegacyCocktails($this)->assertExitCode(0)->run();

    // カクテル 1: 任意の 1 行と「その他」の 1 行を除いて 2。カクテル 2: 旧の値は 3 だが、まとめた後の 4 行。カクテル 3: 材料無し
    expect(DB::table('cocktails')->orderBy('id')->pluck('recipe_count', 'id')->map(cocktailColumnInt(...))->all())
        ->toBe([1 => 2, 2 => 4, 3 => 0])
        ->and(DB::table('cocktails')->where('id', 2)->value('updated_at'))->toBe('2021-02-03 04:05:06');
});

it('TC-31-15: 2 回流しても、カクテル・材料・ID の対応が 1 回目と同じ', function () {
    importLegacyCocktails($this)->assertExitCode(0)->run();
    $first = importedCocktailSnapshot();

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(importedCocktailSnapshot())->toBe($first);
});

it('TC-31-16: 2 回目の前に旧の値が変わっていたら、カクテルを増やさずに書き直し、材料を入れ直す', function () {
    importLegacyCocktails($this)->assertExitCode(0)->run();
    $legacy = DB::connection('legacy');
    $legacy->table('t_cocktails')->where('id', 1)->update(['name' => 'ジン・トニック(変更)', 'base' => 2]);
    $legacy->table('t_recipies')->where('cocktail_id', 1)->where('is_optional', 1)->delete();
    addLegacyRecipe(1, 2, 3, 2, '10ml');

    importLegacyCocktails($this)->assertExitCode(0)->run();

    expect(DB::table('cocktails')->where('id', 1)->value('name'))->toBe('ジン・トニック(変更)')
        ->and(legacyNodeOf(cocktailColumnInt(DB::table('cocktails')->where('id', 1)->value('base_category_id'))))->toBe('m_category:2')
        ->and(legacyIngredientsOf(1))->toBe([
            [1, 'm_category:1', 1, '45ml', false],
            [2, 'm_category:4', null, '適量', false],
            [3, 'm_middle:3', 2, '10ml', false],
        ])
        ->and(DB::table('cocktails')->where('id', 1)->value('recipe_count'))->toBe(3)
        ->and(DB::table('cocktails')->count())->toBe(3)
        ->and(LegacyIdMap::query()->where('legacy_table', 't_cocktails')->count())->toBe(3);
});

it('TC-31-17: 指す先を移した先が無いと、例外で止まり、何も入れない(:dataset)', function (bool $importBrands, ?Closure $breakLegacy) {
    if ($breakLegacy !== null) {
        $breakLegacy(DB::connection('legacy'));
    }
    foreach ($importBrands ? ['misc', 'categories', 'brands'] : ['misc', 'categories'] as $part) {
        LegacyImportCommand::run($this, ['--only' => $part])->assertExitCode(0)->run();
    }

    expect(fn () => LegacyImportCommand::run($this, ['--only' => 'cocktails'])->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('cocktails')->count())->toBe(0)
        ->and(DB::table('cocktail_ingredients')->count())->toBe(0)
        ->and(LegacyIdMap::query()->where('legacy_table', 't_cocktails')->count())->toBe(0);
})->with([
    '銘柄を移していない' => [false, null],
    'ベースが旧に無い' => [true, fn (Connection $legacy) => $legacy->table('t_cocktails')->where('id', 1)->update(['base' => 99])],
    '材料のミドルが旧に無い' => [true, fn (Connection $legacy) => $legacy->table('t_recipies')->where('cocktail_id', 1)->where('category', 4)->update(['middle_category' => 99])],
    '材料の銘柄が旧に無い' => [true, fn (Connection $legacy) => $legacy->table('t_recipies')->where('cocktail_id', 1)->where('category', 4)->update(['brand' => 99])],
]);

it('TC-31-18: --only を付けないと、銘柄の後にカクテルも移す', function () {
    LegacyImportCommand::run($this, [])
        ->expectsOutput('brands:')
        ->expectsOutput('cocktails:')
        ->expectsOutput('cocktails: 3')
        ->assertExitCode(0)
        ->run();

    expect(DB::table('cocktails')->count())->toBe(3)
        ->and(DB::table('cocktail_ingredients')->count())->toBe(7);
});

it('TC-31-19: 1 文のプレースホルダの上限(65,535 個)を超える件数のカクテルも移せる', function () {
    // カクテルの行は 24 列なので、2,853 件を 1 文で書くと 68,472 個になる(本番の旧 DB は 5,707 件)
    $legacy = DB::connection('legacy');
    $template = (array) $legacy->table('t_cocktails')->where('id', 1)->sole();
    $rows = [];
    for ($id = 1001; $id <= 3850; $id++) {
        $rows[] = [...$template, 'id' => $id, 'name' => "カクテル{$id}", 'name_search' => "カクテル{$id}"];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        $legacy->table('t_cocktails')->insert($chunk);
    }
    addLegacyRecipe(3850, 1, null, 1, '45ml');

    importLegacyCocktails($this)->expectsOutput('cocktails: 2853')->assertExitCode(0)->run();

    expect(DB::table('cocktails')->where('id', 3850)->value('name'))->toBe('カクテル3850')
        ->and(legacyIngredientsOf(3850))->toBe([[1, 'm_category:1', 1, '45ml', false]])
        ->and(LegacyIdMap::query()->where('legacy_table', 't_cocktails')->count())->toBe(2853);
});
