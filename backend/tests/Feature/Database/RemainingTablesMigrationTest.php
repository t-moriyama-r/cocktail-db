<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 辞書・言い換え・スキップ語・技法・氷・アフィリエイト・設定のマイグレーション(#23)を、
// migrate:fresh・migrate:rollback で通して確かめる。
// DDL は MariaDB で暗黙にコミットされるので RefreshDatabase は使わず、テストのたびにマイグレーションし直して
// ほかのテストファイル(RefreshDatabase は一度マイグレーションした後は作り直さない)に影響を残さない。

uses()->group('db');

dataset('remaining tables', [
    'methods', 'ices', 'dictionary_categories', 'dictionaries',
    'paraphrases', 'skip_words', 'settings', 'affiliates', 'users',
]);

afterEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

it('TC-23-01: migrate:fresh で表が作られる', function (string $table) {
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeTrue();
})->with('remaining tables');

it('TC-23-02: migrate:rollback で表が消え、もう一度 migrate すると戻る', function (string $table) {
    Artisan::call('migrate:fresh', ['--force' => true]);

    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeFalse()
        ->and(DB::table('migrations')->count())->toBe(0);

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeTrue();
})->with('remaining tables');
