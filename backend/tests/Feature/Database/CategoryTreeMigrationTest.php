<?php

use Tests\Support\MigrationRoundTrip;

// カテゴリ木のマイグレーション(#21)を、migrate:fresh・migrate:rollback で通して確かめる。

uses()->group('db');

dataset('category tree tables', ['categories', 'category_closures', 'legacy_id_maps']);

afterEach(fn () => MigrationRoundTrip::restore());

it('TC-21-01: migrate:fresh でカテゴリ木の表が作られる', function (string $table) {
    MigrationRoundTrip::assertCreatedByFresh($table);
})->with('category tree tables');

it('TC-21-02: migrate:rollback でカテゴリ木の表が消え、もう一度 migrate すると戻る', function (string $table) {
    MigrationRoundTrip::assertRolledBackAndRestored($table);
})->with('category tree tables');
