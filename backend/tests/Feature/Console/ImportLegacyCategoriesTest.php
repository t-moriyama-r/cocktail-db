<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\LegacyIdMap;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;
use Tests\TestCase;

// legacy:import --only=categories(種別・カテゴリ・ミドルをカテゴリ木に移す。#29)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/**
 * legacy:import を呼ぶ。オプションを渡さなければ --only=categories にする。
 *
 * @param  array<string, string>  $options
 */
function importLegacyCategories(TestCase $test, array $options = ['--only' => 'categories']): PendingCommand
{
    return LegacyImportCommand::run($test, $options);
}

/**
 * 旧の表と ID から、移した先のノードを legacy_id_maps で引く。
 */
function importedCategoryNode(string $legacyTable, int $legacyId): Category
{
    $map = LegacyIdMap::query()
        ->where('legacy_table', $legacyTable)
        ->where('legacy_id', $legacyId)
        ->where('new_table', 'categories')
        ->sole();

    return Category::query()->findOrFail($map->new_id);
}

/**
 * 根から $node までの名前。
 *
 * @return list<string>
 */
function importedCategoryPath(Category $node): array
{
    return array_values(app(CategoryTree::class)->path($node)->map(fn (Category $c): string => $c->name)->all());
}

/**
 * カテゴリ木の中身(ノードのすべての列・閉包表・ID の対応)を、比べられる形で返す。
 *
 * @return array<string, list<array<mixed>>>
 */
function importedCategorySnapshot(): array
{
    $rows = function (string $table, string $first, string $second): array {
        $rows = [];
        foreach (DB::table($table)->orderBy($first)->orderBy($second)->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    };

    return [
        'categories' => $rows('categories', 'id', 'id'),
        'category_closures' => $rows('category_closures', 'ancestor_id', 'descendant_id'),
        'legacy_id_maps' => $rows('legacy_id_maps', 'legacy_table', 'legacy_id'),
    ];
}

/**
 * 旧の表の、列 $column の値ごとの行の ID(ID の昇順)。
 *
 * @return array<int, int> 旧 ID => $column の値
 */
function legacyCategoryColumn(string $table, string $column): array
{
    $values = [];
    foreach (DB::connection('legacy')->table($table)->orderBy('id')->get(['id', $column]) as $row) {
        $values[is_numeric($row->id) ? (int) $row->id : throw new RuntimeException("{$table} の id が数でない")] =
            is_numeric($row->{$column}) ? (int) $row->{$column} : throw new RuntimeException("{$table} の {$column} が数でない");
    }

    return $values;
}

/**
 * 旧に「その他」の種別(kind 5)と、その下のカテゴリを足す。LegacyFixture には kind 5 が無い。
 */
function addLegacyOtherKind(): void
{
    $legacy = DB::connection('legacy');
    $legacy->table('m_category_kinds')->insert(['id' => 5, 'name' => 'その他', 'name_eng' => 'Others', 'description' => '']);
    $legacy->table('m_category')->insert([
        'id' => 6, 'kind' => 5, 'name' => 'ミントの葉', 'name_search' => 'ミントの葉', 'name_eng' => 'Mint Leaf',
    ]);
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-29-01: ノードの数が、種別・カテゴリ・ミドルの数の和と一致する', function () {
    importLegacyCategories($this)->assertExitCode(0);

    $legacy = DB::connection('legacy');
    expect(DB::table('categories')->count())
        ->toBe($legacy->table('m_category_kinds')->count() + $legacy->table('m_category')->count() + $legacy->table('m_middle')->count())
        ->toBe(3 + 5 + 3);
});

it('TC-29-02: 移したノードと閉包表の件数を出力する', function () {
    importLegacyCategories($this)
        ->expectsOutput('categories: 11')
        ->expectsOutput('category_closures: 22')
        ->assertExitCode(0);
});

it('TC-29-03: 種別は根、カテゴリは種別の子、ミドルはカテゴリの子になる', function () {
    importLegacyCategories($this)->assertExitCode(0);

    expect(app(CategoryTree::class)->roots()->pluck('name')->all())->toBe(['蒸留酒', '醸造酒', 'ソフトドリンク'])
        ->and(importedCategoryPath(importedCategoryNode('m_category', 1)))->toBe(['蒸留酒', 'ジン'])
        ->and(importedCategoryPath(importedCategoryNode('m_category', 4)))->toBe(['ソフトドリンク', 'トニックウォーター'])
        ->and(importedCategoryPath(importedCategoryNode('m_middle', 1)))->toBe(['醸造酒', 'ワイン', '赤ワイン'])
        ->and(importedCategoryPath(importedCategoryNode('m_middle', 3)))->toBe(['蒸留酒', 'ラム', 'ダークラム']);
});

it('TC-29-04: 閉包表は、どのノードも自分の行とすべての祖先からの行だけを持つ', function () {
    importLegacyCategories($this)->assertExitCode(0);

    $kindOfCategory = legacyCategoryColumn('m_category', 'kind');
    $expected = [];
    foreach (array_keys(legacyCategoryColumn('m_category_kinds', 'id')) as $kindId) {
        $k = importedCategoryNode('m_category_kinds', $kindId)->id;
        $expected[] = [$k, $k, 0];
    }
    foreach ($kindOfCategory as $categoryId => $kindId) {
        $c = importedCategoryNode('m_category', $categoryId)->id;
        $k = importedCategoryNode('m_category_kinds', $kindId)->id;
        array_push($expected, [$c, $c, 0], [$k, $c, 1]);
    }
    foreach (legacyCategoryColumn('m_middle', 'parent') as $middleId => $categoryId) {
        $m = importedCategoryNode('m_middle', $middleId)->id;
        $c = importedCategoryNode('m_category', $categoryId)->id;
        $k = importedCategoryNode('m_category_kinds', $kindOfCategory[$categoryId])->id;
        array_push($expected, [$m, $m, 0], [$c, $m, 1], [$k, $m, 2]);
    }
    sort($expected);

    $actual = [];
    foreach (CategoryClosure::query()->get() as $row) {
        $actual[] = [$row->ancestor_id, $row->descendant_id, $row->depth];
    }
    sort($actual);

    expect($actual)->toBe($expected);
});

it('TC-29-05: legacy_id_maps に、旧の 3 つの表の ID と新しいノードの ID の対応を入れる', function () {
    importLegacyCategories($this)->assertExitCode(0);

    $maps = LegacyIdMap::query()->orderBy('legacy_table')->orderBy('legacy_id')->get();
    $legacy = DB::connection('legacy');
    expect($maps->map(fn (LegacyIdMap $map): array => [$map->legacy_table, $map->legacy_id, $map->new_table])->all())
        ->toBe([
            ['m_category', 1, 'categories'], ['m_category', 2, 'categories'], ['m_category', 3, 'categories'],
            ['m_category', 4, 'categories'], ['m_category', 5, 'categories'],
            ['m_category_kinds', 1, 'categories'], ['m_category_kinds', 2, 'categories'], ['m_category_kinds', 4, 'categories'],
            ['m_middle', 1, 'categories'], ['m_middle', 2, 'categories'], ['m_middle', 3, 'categories'],
        ])
        ->and($maps->pluck('new_id')->unique()->count())->toBe(11);
    foreach ($maps as $map) {
        expect(Category::query()->findOrFail($map->new_id)->name)
            ->toBe($legacy->table($map->legacy_table)->where('id', $map->legacy_id)->value('name'));
    }
});

it('TC-29-06: カテゴリ・ミドルの列の値を旧からそのまま移す(:dataset)', function (string $legacyTable, int $legacyId) {
    DB::connection('legacy')->table($legacyTable)->where('id', $legacyId)->update([
        'description' => '説明の文', 'is_major' => 1, 'is_like' => 1, 'is_powder' => 1,
        'youtube' => 'https://www.youtube.com/watch?v=example', 'youtube_created_at' => '2020-05-06 07:08:09',
        'image' => 'sample.jpg', 'image_txt' => '画像の文', 'image_url' => 'https://example.com/',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);
    $legacy = DB::connection('legacy')->table($legacyTable)->where('id', $legacyId)->sole();

    importLegacyCategories($this)->assertExitCode(0);

    $node = DB::table('categories')->where('id', importedCategoryNode($legacyTable, $legacyId)->id)->sole();
    expect((array) $node)->toMatchArray([
        'name' => $legacy->name, 'name_search' => $legacy->name_search, 'name_eng' => $legacy->name_eng,
        'description' => '説明の文', 'role' => null, 'is_major' => 1, 'is_like' => 1, 'is_powder' => 1,
        'youtube' => 'https://www.youtube.com/watch?v=example', 'youtube_created_at' => '2020-05-06 07:08:09',
        'image' => 'sample.jpg', 'image_txt' => '画像の文', 'image_url' => 'https://example.com/',
        'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
    ]);
})->with([
    'カテゴリ' => ['m_category', 2],
    'ミドル' => ['m_middle', 3],
]);

it('TC-29-07: ミドルの updated_at が null なら null のまま移す', function () {
    DB::connection('legacy')->table('m_middle')->where('id', 1)->update(['updated_at' => null]);

    importLegacyCategories($this)->assertExitCode(0);

    expect(importedCategoryNode('m_middle', 1)->updated_at)->toBeNull();
});

it('TC-29-08: 種別の根は、名前・英名・説明を移し、name_search は名前をそろえた値、フラグは false、日時は null', function () {
    DB::connection('legacy')->table('m_category_kinds')->where('id', 1)
        ->update(['name' => 'ジン・ウォッカ', 'description' => '種別の説明']);

    importLegacyCategories($this)->assertExitCode(0);

    $root = DB::table('categories')->where('id', importedCategoryNode('m_category_kinds', 1)->id)->sole();
    expect((array) $root)->toMatchArray([
        'name' => 'ジン・ウォッカ', 'name_search' => NameNormalizer::normalize('ジン・ウォッカ'), 'name_eng' => 'Spirits',
        'description' => '種別の説明', 'is_major' => 0, 'is_like' => 0, 'is_powder' => 0, 'famous_brand_id' => null,
        'youtube' => null, 'youtube_start_seconds' => null, 'image' => null,
        'created_at' => null, 'updated_at' => null,
    ])->and($root->name_search)->toBe('ジンウォッカ');
});

it('TC-29-09: 根の役割は、旧の種別 4 がソフトドリンク、5 がその他、ほかは無し。根でないノードは持たない', function () {
    addLegacyOtherKind();

    importLegacyCategories($this)->assertExitCode(0);

    $tree = app(CategoryTree::class);
    expect(importedCategoryNode('m_category_kinds', 1)->role)->toBeNull()
        ->and(importedCategoryNode('m_category_kinds', 2)->role)->toBeNull()
        ->and(importedCategoryNode('m_category_kinds', 4)->role?->value)->toBe('soft_drink')
        ->and(importedCategoryNode('m_category_kinds', 5)->role?->value)->toBe('other')
        ->and(DB::table('categories')->whereNotNull('role')->count())->toBe(2)
        ->and($tree->isOther(importedCategoryNode('m_category', 6)))->toBeTrue()
        ->and($tree->isOther(importedCategoryNode('m_category', 4)))->toBeFalse()
        ->and($tree->isNonLiquor(importedCategoryNode('m_category', 4)))->toBeTrue()
        ->and($tree->isNonLiquor(importedCategoryNode('m_middle', 3)))->toBeFalse();
});

it('TC-29-10: YouTube の開始位置を秒にする(:dataset)', function (string $legacyTable, ?int $hour, ?int $minute, ?int $second, ?int $expected) {
    DB::connection('legacy')->table($legacyTable)->where('id', 1)
        ->update(['y_hour' => $hour, 'y_minute' => $minute, 'y_second' => $second]);

    importLegacyCategories($this)->assertExitCode(0);

    expect(importedCategoryNode($legacyTable, 1)->youtube_start_seconds)->toBe($expected);
})->with([
    'カテゴリ・3 つとも null' => ['m_category', null, null, null, null],
    'カテゴリ・分と秒' => ['m_category', null, 2, 30, 150],
    'ミドル・秒だけ' => ['m_middle', null, null, 59, 59],
    'ミドル・時・分・秒' => ['m_middle', 1, 2, 3, 3723],
]);

it('TC-29-11: 有名な銘柄は、旧で値があっても null で入れる(銘柄を移す #30 で付ける)', function () {
    DB::connection('legacy')->table('m_category')->where('id', 1)->update(['famous_brand_id' => 1]);
    DB::connection('legacy')->table('m_middle')->where('id', 1)->update(['famous_brand_id' => 0]);

    importLegacyCategories($this)->assertExitCode(0);

    expect(DB::table('categories')->whereNotNull('famous_brand_id')->count())->toBe(0);
});

it('TC-29-12: 並び順は、同じ親の下で旧の ID の昇順に 1 から振る(根は種別の ID の順)', function () {
    // ID の順と名前の順が逆になるよう、ID の大きい方を先に入れる
    DB::connection('legacy')->table('m_category')->insert([
        'id' => 9, 'kind' => 1, 'name' => 'アクアビット', 'name_search' => 'アクアビット', 'name_eng' => 'Aquavit',
    ]);

    importLegacyCategories($this)->assertExitCode(0);

    $sortOrder = fn (string $table, int $id): int => importedCategoryNode($table, $id)->sort_order;
    expect([$sortOrder('m_category_kinds', 1), $sortOrder('m_category_kinds', 2), $sortOrder('m_category_kinds', 4)])->toBe([1, 2, 3])
        ->and([$sortOrder('m_category', 1), $sortOrder('m_category', 2), $sortOrder('m_category', 9)])->toBe([1, 2, 3])
        ->and([$sortOrder('m_category', 4), $sortOrder('m_category', 5)])->toBe([1, 2])
        ->and([$sortOrder('m_middle', 1), $sortOrder('m_middle', 2), $sortOrder('m_middle', 3)])->toBe([1, 2, 1])
        ->and(app(CategoryTree::class)->children(importedCategoryNode('m_category_kinds', 1))->pluck('name')->all())
        ->toBe(['ジン', 'ラム', 'アクアビット']);
});

it('TC-29-13: 2 回流しても、ノード・閉包表・ID の対応が 1 回目と同じ', function () {
    importLegacyCategories($this)->assertExitCode(0);
    $first = importedCategorySnapshot();

    importLegacyCategories($this)->assertExitCode(0);

    expect(importedCategorySnapshot())->toBe($first);
});

it('TC-29-14: 2 回目の前に旧の値が変わっていたら、ノードを増やさずに値を書き直す', function () {
    importLegacyCategories($this)->assertExitCode(0);
    $id = importedCategoryNode('m_middle', 1)->id;
    DB::connection('legacy')->table('m_middle')->where('id', 1)->update(['name' => '赤ワイン(変更)', 'is_major' => 1]);
    DB::connection('legacy')->table('m_category_kinds')->where('id', 2)->update(['name' => '醸造酒(変更)']);

    importLegacyCategories($this)->assertExitCode(0);

    $node = importedCategoryNode('m_middle', 1);
    expect($node->id)->toBe($id)
        ->and([$node->name, $node->is_major])->toBe(['赤ワイン(変更)', true])
        ->and(importedCategoryNode('m_category_kinds', 2)->name_search)->toBe('醸造酒(変更)')
        ->and(DB::table('categories')->count())->toBe(11)
        ->and(DB::table('category_closures')->count())->toBe(22);
});

it('TC-29-15: 2 回目の前に旧の親が変わっていたら、ノードを新しい親の下へ移す', function () {
    importLegacyCategories($this)->assertExitCode(0);
    DB::connection('legacy')->table('m_middle')->where('id', 3)->update(['parent' => 1]);
    DB::connection('legacy')->table('m_category')->where('id', 3)->update(['kind' => 1]);

    importLegacyCategories($this)->assertExitCode(0);

    expect(importedCategoryPath(importedCategoryNode('m_middle', 3)))->toBe(['蒸留酒', 'ジン', 'ダークラム'])
        ->and(importedCategoryPath(importedCategoryNode('m_middle', 1)))->toBe(['蒸留酒', 'ワイン', '赤ワイン'])
        ->and(DB::table('category_closures')->count())->toBe(22);
});

it('TC-29-16: 2 回目は、銘柄を移した後に付けた有名な銘柄を消さない', function () {
    importLegacyCategories($this)->assertExitCode(0);
    $gin = importedCategoryNode('m_category', 1);
    $brand = Brand::factory()->create(['category_id' => $gin->id]);
    DB::table('categories')->where('id', $gin->id)->update(['famous_brand_id' => $brand->id]);

    importLegacyCategories($this)->assertExitCode(0);

    expect(importedCategoryNode('m_category', 1)->famous_brand_id)->toBe($brand->id);
});

it('TC-29-17: 親が旧に無いカテゴリ・ミドルがあると、例外で止まり、何も入れない(:dataset)', function (string $legacyTable, string $parentColumn, int $missingParent) {
    DB::connection('legacy')->table($legacyTable)->where('id', 1)->update([$parentColumn => $missingParent]);

    expect(fn () => importLegacyCategories($this)->run())->toThrow(UnexpectedValueException::class);

    expect(DB::table('categories')->count())->toBe(0)
        ->and(DB::table('category_closures')->count())->toBe(0)
        ->and(DB::table('legacy_id_maps')->count())->toBe(0);
})->with([
    '種別が無いカテゴリ' => ['m_category', 'kind', 9],
    'カテゴリが無いミドル' => ['m_middle', 'parent', 99],
]);

it('TC-29-18: --only を付けないと、カテゴリ木も移す', function () {
    importLegacyCategories($this, [])->assertExitCode(0);

    expect(DB::table('categories')->count())->toBe(11);
});
