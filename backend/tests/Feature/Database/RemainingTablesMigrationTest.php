<?php

use Tests\Support\MigrationRoundTrip;

// 辞書・言い換え・スキップ語・技法・氷・アフィリエイト・設定のマイグレーション(#23)を、
// migrate:fresh・migrate:rollback で通して確かめる。users は Laravel の雛形のまま使うので、あわせて確かめる。

uses()->group('db');

dataset('remaining tables', [
    'methods', 'ices', 'dictionary_categories', 'dictionaries',
    'paraphrases', 'skip_words', 'settings', 'affiliates', 'users',
]);

afterEach(fn () => MigrationRoundTrip::restore());

it('TC-23-01: migrate:fresh で表が作られる', function (string $table) {
    MigrationRoundTrip::assertCreatedByFresh($table);
})->with('remaining tables');

it('TC-23-02: migrate:rollback で表が消え、もう一度 migrate すると戻る', function (string $table) {
    MigrationRoundTrip::assertRolledBackAndRestored($table);
})->with('remaining tables');
