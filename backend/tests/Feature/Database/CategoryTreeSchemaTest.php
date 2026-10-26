<?php

use App\Models\Category;
use App\Models\LegacyIdMap;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// カテゴリ木の表(#21)の形を docs/schema.md と突き合わせる。閉包表(category_closures)は CategoryTree(#24)だけが
// 書き換えるので、ここでは行を入れず、表の定義(列・索引・外部キー)を読んで確かめる。

uses(RefreshDatabase::class)->group('db');

it('TC-21-03: categories の列の型・null 可・既定値が設計どおり', function () {
    expect(columnsOf('categories'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_search' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', false, null],
        'description' => ['text', true, 'NULL'],
        'role' => ['varchar(20)', true, 'NULL'],
        'is_major' => ['tinyint(1)', false, '0'],
        'is_like' => ['tinyint(1)', false, '0'],
        'is_powder' => ['tinyint(1)', false, '0'],
        'famous_brand_id' => ['bigint(20) unsigned', true, 'NULL'],
        'youtube' => ['varchar(255)', true, 'NULL'],
        'youtube_created_at' => ['datetime', true, 'NULL'],
        'youtube_start_seconds' => ['int(10) unsigned', true, 'NULL'],
        'image' => ['varchar(255)', true, 'NULL'],
        'image_txt' => ['varchar(255)', true, 'NULL'],
        'image_url' => ['text', true, 'NULL'],
        'sort_order' => ['int(10) unsigned', false, '0'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ]);
});

it('TC-21-04: categories の索引は旧と同じ 5 列と主キーだけで、外部キーはまだ無い', function () {
    expect(indexesOf('categories'))->toEqualCanonicalizing([
        'primary' => ['id'],
        'categories_name_search_index' => ['name_search'],
        'categories_name_eng_index' => ['name_eng'],
        'categories_is_major_index' => ['is_major'],
        'categories_is_like_index' => ['is_like'],
        'categories_youtube_created_at_index' => ['youtube_created_at'],
    ])
        // famous_brand_id の外部キーは brands を作る #22 で足す
        ->and(Schema::getForeignKeys('categories'))->toBe([]);
});

it('TC-21-05: category_closures の列・主キー・索引が設計どおり', function () {
    expect(columnsOf('category_closures'))->toBe([
        'ancestor_id' => ['bigint(20) unsigned', false, null],
        'descendant_id' => ['bigint(20) unsigned', false, null],
        'depth' => ['smallint(5) unsigned', false, null],
    ])
        ->and(indexesOf('category_closures'))->toEqualCanonicalizing([
            'primary' => ['ancestor_id', 'descendant_id'],
            'category_closures_descendant_id_depth_index' => ['descendant_id', 'depth'],
        ]);
});

it('TC-21-06: category_closures の 2 つの外部キーが categories を指し、削除は CASCADE', function () {
    expect(foreignKeysOf('category_closures'))->toEqualCanonicalizing([
        ['ancestor_id', 'categories', 'id', 'cascade'],
        ['descendant_id', 'categories', 'id', 'cascade'],
    ]);
});

it('TC-21-07: legacy_id_maps の列・主キー・索引が設計どおりで、外部キーは無い', function () {
    expect(columnsOf('legacy_id_maps'))->toBe([
        'legacy_table' => ['varchar(64)', false, null],
        'legacy_id' => ['int(10) unsigned', false, null],
        'new_table' => ['varchar(64)', false, null],
        'new_id' => ['bigint(20) unsigned', false, null],
    ])
        ->and(indexesOf('legacy_id_maps'))->toEqualCanonicalizing([
            'primary' => ['legacy_table', 'legacy_id'],
            'legacy_id_maps_new_table_new_id_index' => ['new_table', 'new_id'],
        ])
        ->and(Schema::getForeignKeys('legacy_id_maps'))->toBe([]);
});

it('TC-21-08: Category の factory で、既定値の入ったノードを作って読み戻せる(4 バイト文字も欠けない)', function () {
    $category = Category::factory()->create(['name' => '🍸ラム𩸽']);

    $loaded = Category::findOrFail($category->id);

    expect($loaded->name)->toBe('🍸ラム𩸽')
        ->and($loaded->is_major)->toBeFalse()
        ->and($loaded->is_like)->toBeFalse()
        ->and($loaded->is_powder)->toBeFalse()
        ->and($loaded->sort_order)->toBe(0)
        ->and($loaded->role)->toBeNull()
        ->and($loaded->famous_brand_id)->toBeNull();
});

it('TC-21-09: factory のノードは閉包表の行を持たず、親・子・祖先・子孫がどれも無い', function () {
    $category = Category::factory()->create();

    expect(DB::table('category_closures')->count())->toBe(0)
        ->and($category->parent)->toBeNull()
        ->and($category->children)->toBeEmpty()
        ->and($category->ancestors)->toBeEmpty()
        ->and($category->descendants)->toBeEmpty();
});

it('TC-21-10: 親・子・祖先・子孫のリレーションは閉包表を、向きと段数で引く', function () {
    $category = Category::factory()->make(['id' => 7]);

    expect($category->parent()->toRawSql())
        ->toContain('`category_closures`.`descendant_id` = 7')
        ->toContain('`category_closures`.`ancestor_id` = `categories`.`id`')
        ->toContain('`category_closures`.`depth` = 1');
    expect($category->children()->toRawSql())
        ->toContain('`category_closures`.`ancestor_id` = 7')
        ->toContain('`categories`.`id` = `category_closures`.`descendant_id`')
        ->toContain('`category_closures`.`depth` = 1');
    expect($category->ancestors()->toRawSql())
        ->toContain('`category_closures`.`descendant_id` = 7')
        ->toContain('`categories`.`id` = `category_closures`.`ancestor_id`')
        ->toContain('`category_closures`.`depth` > 0');
    expect($category->descendants()->toRawSql())
        ->toContain('`category_closures`.`ancestor_id` = 7')
        ->toContain('`categories`.`id` = `category_closures`.`descendant_id`')
        ->toContain('`category_closures`.`depth` > 0');
});

it('TC-21-11: LegacyIdMap は、旧の表が違えば同じ旧 ID を持て、更新は (表, ID) の 1 行だけに効く', function () {
    LegacyIdMap::factory()->create(['legacy_table' => 'm_category', 'legacy_id' => 3, 'new_id' => 10]);
    $middle = LegacyIdMap::factory()->create(['legacy_table' => 'm_middle', 'legacy_id' => 3, 'new_id' => 20]);

    $middle->update(['new_id' => 21]);

    expect(LegacyIdMap::query()->orderBy('legacy_table')->pluck('new_id', 'legacy_table')->all())
        ->toBe(['m_category' => 10, 'm_middle' => 21]);
});

it('TC-21-12: LegacyIdMap は、同じ (旧の表, 旧 ID) を 2 行入れられない', function () {
    LegacyIdMap::factory()->create(['legacy_table' => 'm_category', 'legacy_id' => 3]);

    expect(fn () => LegacyIdMap::factory()->create(['legacy_table' => 'm_category', 'legacy_id' => 3]))
        ->toThrow(QueryException::class);
});

/**
 * 列の名前ごとに [型, null 可, 既定値] を、表の列の順に返す。
 *
 * @return array<string, array{string, bool, string|null}>
 */
function columnsOf(string $table): array
{
    return collect(Schema::getColumns($table))
        ->mapWithKeys(fn (array $column) => [$column['name'] => [$column['type'], $column['nullable'], $column['default']]])
        ->all();
}

/**
 * 索引の名前ごとに、索引の列を返す。
 *
 * @return array<string, list<string>>
 */
function indexesOf(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->mapWithKeys(fn (array $index) => [$index['name'] => $index['columns']])
        ->all();
}

/**
 * 外部キーごとに [列, 指す表, 指す列, 削除のとき] を返す。
 *
 * @return list<array{string, string, string, string}>
 */
function foreignKeysOf(string $table): array
{
    return collect(Schema::getForeignKeys($table))
        ->map(fn (array $key) => [
            implode(',', $key['columns']),
            $key['foreign_table'],
            implode(',', $key['foreign_columns']),
            strtolower($key['on_delete']),
        ])
        ->all();
}
