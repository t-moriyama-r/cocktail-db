<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyFixture;

// 旧 DB の接続(legacy)と、テスト用の旧データ。DB を使うものは db グループに入れる(手元に MariaDB が無いときは飛ばす)

it('TC-27-01: legacy 接続は LEGACY_DB_* から読み、MariaDB のテスト用の DB を向く', function () {
    $config = config('database.connections.legacy');

    expect($config['driver'])->toBe('mariadb')
        ->and($config['database'])->toBe(env('LEGACY_DB_DATABASE'))
        ->and($config['database'])->toBe('legacy_test');
});

it('TC-27-02: schema.sql に DB 名・AUTO_INCREMENT の値・DEFINER・データが入っていない', function () {
    $sql = file_get_contents(database_path('legacy/schema.sql'));

    expect($sql)->toContain('CREATE TABLE `t_cocktails`')
        ->not->toMatch('/AUTO_INCREMENT\s*=\s*\d/i')
        ->not->toMatch('/DEFINER/i')
        ->not->toMatch('/^\s*(CREATE DATABASE|USE\s)/mi')
        ->not->toMatch('/^\s*INSERT\s/mi');
});

it('TC-27-07: legacy 接続の DB 名が _test で終わらなければ、接続せずに止める', function () {
    config()->set('database.connections.legacy.database', 'legacy');
    DB::purge('legacy');

    expect(fn () => LegacyFixture::load())->toThrow(RuntimeException::class, '_test で終わらない');
    expect(DB::connection('legacy')->getRawPdo())->toBeInstanceOf(Closure::class);
});

it('TC-27-03: 旧 DB の 16 テーブルが作られる', function () {
    LegacyFixture::load();

    $tables = collect(Schema::connection('legacy')->getTables())->pluck('name')->sort()->values()->all();

    expect($tables)->toBe([
        'affiliates', 'm_auto_input', 'm_brand', 'm_category', 'm_category_kinds', 'm_dictionary_category',
        'm_ice', 'm_method', 'm_middle', 'migrations', 'paraphrases', 'skip_db', 't_cocktails',
        't_dictionary', 't_recipies', 'users',
    ]);
})->group('db');

it('TC-27-04: 入れた旧データを legacy 接続で読める', function () {
    LegacyFixture::load();

    $legacy = DB::connection('legacy');
    $cocktail = $legacy->table('t_cocktails')->where('id', 1)->first();
    $recipes = $legacy->table('t_recipies')
        ->join('m_category', 'm_category.id', '=', 't_recipies.category')
        ->where('t_recipies.cocktail_id', 1)
        ->orderBy('m_category.id')
        ->pluck('t_recipies.amount', 'm_category.name')
        ->all();

    expect($cocktail->name)->toBe('ジン・トニック')
        ->and($cocktail->base)->toBe(1)
        ->and($recipes)->toBe(['ジン' => '45ml', 'トニックウォーター' => '適量', 'ライムジュース' => '1tsp']);
})->group('db');

it('TC-27-05: 旧データの異常の例が入っている(:dataset)', function (string $sql, int $expected) {
    LegacyFixture::load();

    expect((int) DB::connection('legacy')->scalar($sql))->toBe($expected);
})->with([
    'カクテルが消えた後に残ったレシピの行' => [
        'SELECT COUNT(*) FROM t_recipies r LEFT JOIN t_cocktails c ON c.id = r.cocktail_id WHERE c.id IS NULL',
        2,
    ],
    '同じカクテルで材料・銘柄まで同じレシピの組' => [
        'SELECT COUNT(*) FROM (SELECT 1 FROM t_recipies GROUP BY cocktail_id, category, middle_category, brand HAVING COUNT(*) > 1) d',
        2,
    ],
    'ミドルの親とレシピのカテゴリが合わない行' => [
        'SELECT COUNT(*) FROM t_recipies r JOIN m_middle m ON m.id = r.middle_category WHERE m.parent <> r.category',
        1,
    ],
    '銘柄のカテゴリと、そのミドルの親が合わない銘柄' => [
        'SELECT COUNT(*) FROM m_brand b JOIN m_middle m ON m.id = b.middle_category WHERE m.parent <> b.category',
        1,
    ],
    'recipe_count が任意でないレシピの行の数と合わないカクテル' => [
        'SELECT COUNT(*) FROM t_cocktails c WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r WHERE r.cocktail_id = c.id AND r.is_optional = 0)',
        1,
    ],
    'name_search が表をまたいで重複' => [
        'SELECT COUNT(*) FROM m_brand b JOIN m_category c ON c.name_search = b.name_search',
        1,
    ],
    'アフィリエイトのミドルが 0' => ['SELECT COUNT(*) FROM affiliates WHERE middle_id = 0', 2],
    'アフィリエイトの銘柄が 0' => ['SELECT COUNT(*) FROM affiliates WHERE brand_id = 0', 1],
])->group('db');

it('TC-27-06: 何度入れ直しても同じ件数になる', function () {
    LegacyFixture::load();
    DB::connection('legacy')->table('t_cocktails')->insert([
        'id' => 50, 'name' => '途中で足した行', 'name_search' => '途中で足した行', 'base' => 1,
        'volume' => 0, 'method' => 1, 'recipe_count' => 0,
    ]);

    LegacyFixture::load();

    $legacy = DB::connection('legacy');
    expect($legacy->table('t_cocktails')->count())->toBe(3)
        ->and($legacy->table('t_recipies')->count())->toBe(10);
})->group('db');

it('TC-27-08: 既定の DB には旧 DB の表を作らない', function () {
    LegacyFixture::load();

    expect(Schema::hasTable('t_cocktails'))->toBeFalse();
})->group('db');
