<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// カテゴリ木のマイグレーション(#21)を、migrate:fresh・migrate:rollback で通して確かめる。
// DDL は MariaDB で暗黙にコミットされるので RefreshDatabase は使わず、最後に必ずマイグレーションし直して
// ほかのテストファイル(RefreshDatabase は一度マイグレーションした後は作り直さない)に影響を残さない。

uses()->group('db');

const CATEGORY_TREE_TABLES = ['categories', 'category_closures', 'legacy_id_maps'];

it('TC-21-01: migrate:fresh でカテゴリ木の 3 つの表が作られる', function () {
    try {
        expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);

        foreach (CATEGORY_TREE_TABLES as $table) {
            expect(Schema::hasTable($table))->toBeTrue("{$table} が無い");
        }
    } finally {
        Artisan::call('migrate', ['--force' => true]);
    }
});

it('TC-21-02: migrate:rollback で 3 つの表が消え、もう一度 migrate すると戻る', function () {
    try {
        Artisan::call('migrate:fresh', ['--force' => true]);

        expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0);

        foreach (CATEGORY_TREE_TABLES as $table) {
            expect(Schema::hasTable($table))->toBeFalse("{$table} が残っている");
        }
        expect(DB::table('migrations')->count())->toBe(0);

        expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

        foreach (CATEGORY_TREE_TABLES as $table) {
            expect(Schema::hasTable($table))->toBeTrue("{$table} が戻っていない");
        }
    } finally {
        Artisan::call('migrate', ['--force' => true]);
    }
});
