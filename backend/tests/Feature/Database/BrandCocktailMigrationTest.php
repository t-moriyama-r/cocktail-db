<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 銘柄・カクテル・材料のマイグレーション(#22)を、migrate:fresh・migrate:rollback で通して確かめる。
// DDL は MariaDB で暗黙にコミットされるので RefreshDatabase は使わず、テストのたびにマイグレーションし直して
// ほかのテストファイル(RefreshDatabase は一度マイグレーションした後は作り直さない)に影響を残さない。

uses()->group('db');

dataset('brand and cocktail tables', ['brands', 'cocktails', 'cocktail_ingredients']);

afterEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

it('TC-22-01: migrate:fresh で銘柄・カクテル・材料の表が作られる', function (string $table) {
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeTrue();
})->with('brand and cocktail tables');

it('TC-22-02: migrate:rollback で銘柄・カクテル・材料の表が消え、もう一度 migrate すると戻る', function (string $table) {
    Artisan::call('migrate:fresh', ['--force' => true]);

    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeFalse()
        ->and(DB::table('migrations')->count())->toBe(0);

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeTrue();
})->with('brand and cocktail tables');

it('TC-22-03: famous_brand_id の外部キーを足すマイグレーションだけを戻すと、categories から外部キーと索引が消える', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $migration = '2026_10_27_000004_add_famous_brand_foreign_key_to_categories_table';
    $steps = DB::table('migrations')->where('migration', '>=', $migration)->count();

    expect(Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]))->toBe(0)
        ->and(Schema::hasTable('brands'))->toBeTrue()
        ->and(Schema::getForeignKeys('categories'))->toBe([])
        ->and(Schema::hasIndex('categories', ['famous_brand_id']))->toBeFalse();
});
