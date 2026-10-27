<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\TestCase;

// legacy:import --only=brands(銘柄を移し、ノードに有名な銘柄を付ける。#30)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/**
 * カテゴリ木を移してから、銘柄を移す。
 */
function importLegacyBrands(TestCase $test): PendingCommand
{
    LegacyImportCommand::run($test, ['--only' => 'categories'])->assertExitCode(0)->run();

    return LegacyImportCommand::run($test, ['--only' => 'brands']);
}

/**
 * 旧の表と ID から、移した先のノードの ID を legacy_id_maps で引く。
 */
function brandNodeId(string $legacyTable, int $legacyId): int
{
    return LegacyIdMap::query()
        ->where('legacy_table', $legacyTable)
        ->where('legacy_id', $legacyId)
        ->where('new_table', 'categories')
        ->sole()
        ->new_id;
}

/**
 * 根から銘柄の所属ノードまでの名前。
 *
 * @return list<string>
 */
function brandPath(int $brandId): array
{
    $node = Brand::query()->findOrFail($brandId)->category()->firstOrFail();

    return array_values(app(CategoryTree::class)->path($node)->map(fn (Category $c): string => $c->name)->all());
}

/**
 * 銘柄の移行で書く表(銘柄・ID の対応・ノードの有名な銘柄と更新日時)を、比べられる形で返す。
 *
 * @return array<string, list<array<mixed>>>
 */
function importedBrandSnapshot(): array
{
    $rows = function (string $table, array $columns, string ...$orderBy): array {
        $query = DB::table($table)->select($columns);
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
        'brands' => $rows('brands', ['*'], 'id'),
        'legacy_id_maps' => $rows('legacy_id_maps', ['*'], 'legacy_table', 'legacy_id'),
        'categories' => $rows('categories', ['id', 'famous_brand_id', 'updated_at'], 'id'),
    ];
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-30-01: 銘柄の件数が旧と同じで、件数を出力する', function () {
    importLegacyBrands($this)
        ->expectsOutput('brands: 4')
        ->assertExitCode(0)
        ->run();

    expect(DB::table('brands')->count())
        ->toBe(DB::connection('legacy')->table('m_brand')->count())
        ->toBe(4);
});

it('TC-30-02: 銘柄の ID を旧 ID のまま入れ、legacy_id_maps に同じ値の対応を入れる', function () {
    importLegacyBrands($this)->assertExitCode(0)->run();

    $legacyIds = DB::connection('legacy')->table('m_brand')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $maps = LegacyIdMap::query()->where('legacy_table', 'm_brand')->orderBy('legacy_id')->get();
    expect(Brand::query()->orderBy('id')->pluck('id')->all())->toBe($legacyIds)
        ->and($maps->map(fn (LegacyIdMap $map): array => [$map->legacy_id, $map->new_table, $map->new_id])->all())
        ->toBe(array_map(fn (int $id): array => [$id, 'brands', $id], $legacyIds));
});

it('TC-30-03: ミドルがあればミドルのノード、無ければカテゴリのノードに付ける', function () {
    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(Brand::query()->findOrFail(1)->category_id)->toBe(brandNodeId('m_category', 1))
        ->and(brandPath(1))->toBe(['蒸留酒', 'ジン'])
        ->and(Brand::query()->findOrFail(2)->category_id)->toBe(brandNodeId('m_middle', 3))
        ->and(brandPath(2))->toBe(['蒸留酒', 'ラム', 'ダークラム'])
        ->and(brandPath(3))->toBe(['ソフトドリンク', 'トニックウォーター']);
});

it('TC-30-04: ミドルの親とカテゴリが合わない銘柄は、カテゴリの列を見ずにミドルのノードに付ける(#28)', function () {
    // LegacyFixture の銘柄 4 は、カテゴリ 1(ジン)・ミドル 3(親はカテゴリ 2 のラム)
    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(Brand::query()->findOrFail(4)->category_id)->toBe(brandNodeId('m_middle', 3))
        ->and(brandPath(4))->toBe(['蒸留酒', 'ラム', 'ダークラム']);
});

it('TC-30-05: ミドルが 0 の銘柄は、ミドル無しとしてカテゴリのノードに付ける', function () {
    DB::connection('legacy')->table('m_brand')->where('id', 1)->update(['middle_category' => 0]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(Brand::query()->findOrFail(1)->category_id)->toBe(brandNodeId('m_category', 1));
});

it('TC-30-06: 銘柄の列の値を旧からそのまま移す', function () {
    DB::connection('legacy')->table('m_brand')->where('id', 2)->update([
        'description' => '銘柄の説明', 'is_major' => 1, 'is_like' => 1, 'instagram' => 'https://www.instagram.com/p/example/',
        'youtube' => 'https://www.youtube.com/watch?v=example', 'youtube_created_at' => '2020-05-06 07:08:09',
        'image' => 'sample.jpg', 'image_txt' => '画像の文', 'image_url' => 'https://example.com/',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect((array) DB::table('brands')->where('id', 2)->sole())->toMatchArray([
        'name' => 'サンプル・ダークラム', 'name_search' => 'サンプル・ダークラム', 'name_eng' => 'Sample Dark Rum',
        'volume' => '40.0', 'description' => '銘柄の説明', 'is_major' => 1, 'is_like' => 1,
        'instagram' => 'https://www.instagram.com/p/example/',
        'youtube' => 'https://www.youtube.com/watch?v=example', 'youtube_created_at' => '2020-05-06 07:08:09',
        'image' => 'sample.jpg', 'image_txt' => '画像の文', 'image_url' => 'https://example.com/',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);
});

it('TC-30-07: 旧の float の度数を、小数 1 桁の値にして移す(:dataset)', function (float $legacy, string $expected) {
    DB::connection('legacy')->table('m_brand')->where('id', 1)->update(['volume' => $legacy]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(DB::table('brands')->where('id', 1)->value('volume'))->toBe($expected);
})->with([
    '0' => [0.0, '0.0'],
    '2 進で表せない 1.7' => [1.7, '1.7'],
    '2 進で表せない 41.2' => [41.2, '41.2'],
    '旧の最大 96' => [96.0, '96.0'],
]);

it('TC-30-08: 度数が小数 1 桁に収まらないと、例外で止まり、銘柄を 1 件も入れない', function () {
    DB::connection('legacy')->table('m_brand')->where('id', 3)->update(['volume' => 12.34]);

    expect(fn () => importLegacyBrands($this)->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('brands')->count())->toBe(0)
        ->and(LegacyIdMap::query()->where('legacy_table', 'm_brand')->count())->toBe(0);
});

it('TC-30-09: YouTube の開始位置を秒にする(:dataset)', function (?int $hour, ?int $minute, ?int $second, ?int $expected) {
    DB::connection('legacy')->table('m_brand')->where('id', 1)
        ->update(['y_hour' => $hour, 'y_minute' => $minute, 'y_second' => $second]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(Brand::query()->findOrFail(1)->youtube_start_seconds)->toBe($expected);
})->with([
    '3 つとも null' => [null, null, null, null],
    '分と秒' => [null, 2, 30, 150],
    '時・分・秒' => [1, 2, 3, 3723],
]);

it('TC-30-10: updated_at が null なら null のまま移す', function () {
    DB::connection('legacy')->table('m_brand')->where('id', 1)->update(['updated_at' => null]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(DB::table('brands')->where('id', 1)->value('updated_at'))->toBeNull();
});

it('TC-30-11: カテゴリ・ミドルの有名な銘柄を、移したノードに付ける。0 と null は null', function () {
    $legacy = DB::connection('legacy');
    $legacy->table('m_category')->where('id', 1)->update(['famous_brand_id' => 1]);
    $legacy->table('m_category')->where('id', 2)->update(['famous_brand_id' => 0]);
    $legacy->table('m_middle')->where('id', 3)->update(['famous_brand_id' => 2]);
    $legacy->table('m_middle')->where('id', 1)->update(['famous_brand_id' => 0]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    $famous = fn (string $table, int $id): ?int => Category::query()->findOrFail(brandNodeId($table, $id))->famous_brand_id;
    expect($famous('m_category', 1))->toBe(1)
        ->and($famous('m_middle', 3))->toBe(2)
        ->and($famous('m_category', 2))->toBeNull()
        ->and($famous('m_middle', 1))->toBeNull()
        ->and(DB::table('categories')->whereNotNull('famous_brand_id')->count())->toBe(2);
});

it('TC-30-12: 有名な銘柄を付けても、ノードの更新日時は旧の値のまま', function () {
    $legacy = DB::connection('legacy');
    $legacy->table('m_category')->where('id', 1)->update(['famous_brand_id' => 1, 'updated_at' => '2021-02-03 04:05:06']);
    $legacy->table('m_middle')->where('id', 3)->update(['famous_brand_id' => 2, 'updated_at' => null]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(DB::table('categories')->where('id', brandNodeId('m_category', 1))->value('updated_at'))->toBe('2021-02-03 04:05:06')
        ->and(DB::table('categories')->where('id', brandNodeId('m_middle', 3))->value('updated_at'))->toBeNull();
});

it('TC-30-13: 2 回流しても、銘柄・ID の対応・ノードの有名な銘柄が 1 回目と同じ', function () {
    DB::connection('legacy')->table('m_category')->where('id', 1)->update(['famous_brand_id' => 1]);
    importLegacyBrands($this)->assertExitCode(0)->run();
    $first = importedBrandSnapshot();

    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(importedBrandSnapshot())->toBe($first);
});

it('TC-30-14: 2 回目の前に旧の値が変わっていたら、銘柄を増やさずに書き直す', function () {
    importLegacyBrands($this)->assertExitCode(0)->run();
    $legacy = DB::connection('legacy');
    $legacy->table('m_brand')->where('id', 1)->update(['name' => 'サンプル・ドライジン(変更)', 'middle_category' => 3]);
    $legacy->table('m_category')->where('id', 1)->update(['famous_brand_id' => 1]);

    importLegacyBrands($this)->assertExitCode(0)->run();

    $brand = Brand::query()->findOrFail(1);
    expect($brand->name)->toBe('サンプル・ドライジン(変更)')
        ->and($brand->category_id)->toBe(brandNodeId('m_middle', 3))
        ->and(Category::query()->findOrFail(brandNodeId('m_category', 1))->famous_brand_id)->toBe(1)
        ->and(DB::table('brands')->count())->toBe(4)
        ->and(LegacyIdMap::query()->where('legacy_table', 'm_brand')->count())->toBe(4);

    // 有名な銘柄を外したら、2 回目で null に戻る
    $legacy->table('m_category')->where('id', 1)->update(['famous_brand_id' => 0]);
    importLegacyBrands($this)->assertExitCode(0)->run();

    expect(Category::query()->findOrFail(brandNodeId('m_category', 1))->famous_brand_id)->toBeNull();
});

it('TC-30-15: 所属するノードが無いと、例外で止まり、何も入れない(:dataset)', function (bool $importCategories, array $change) {
    if ($change !== []) {
        DB::connection('legacy')->table('m_brand')->where('id', 1)->update($change);
    }
    if ($importCategories) {
        LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();
    }
    $categoriesBefore = importedBrandSnapshot()['categories'];

    expect(fn () => LegacyImportCommand::run($this, ['--only' => 'brands'])->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('brands')->count())->toBe(0)
        ->and(LegacyIdMap::query()->where('legacy_table', 'm_brand')->count())->toBe(0)
        ->and(importedBrandSnapshot()['categories'])->toBe($categoriesBefore);
})->with([
    'カテゴリ木を移していない' => [false, []],
    'ミドルが旧に無い' => [true, ['middle_category' => 99]],
    'カテゴリが旧に無い' => [true, ['category' => 99]],
]);

it('TC-30-16: 有名な銘柄が旧の銘柄に無いと、例外で止まり、何も入れない', function () {
    DB::connection('legacy')->table('m_middle')->where('id', 1)->update(['famous_brand_id' => 99]);

    expect(fn () => importLegacyBrands($this)->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('brands')->count())->toBe(0)
        ->and(DB::table('categories')->whereNotNull('famous_brand_id')->count())->toBe(0);
});

it('TC-30-17: --only を付けないと、カテゴリ木の後に銘柄も移す', function () {
    DB::connection('legacy')->table('m_middle')->where('id', 3)->update(['famous_brand_id' => 2]);

    LegacyImportCommand::run($this, [])->expectsOutput('brands:')->expectsOutput('brands: 4')->assertExitCode(0)->run();

    expect(DB::table('brands')->count())->toBe(4)
        ->and(Category::query()->findOrFail(brandNodeId('m_middle', 3))->famous_brand_id)->toBe(2);
});
