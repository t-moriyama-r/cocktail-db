<?php

use Tests\Support\MigrationRoundTrip;

// 管理画面のパスキーの表(#311)のマイグレーションを、migrate:fresh・migrate:rollback で通して確かめる。

uses()->group('db');

afterEach(fn () => MigrationRoundTrip::restore());

it('TC-311-22: migrate:fresh で passkeys が作られる', function () {
    MigrationRoundTrip::assertCreatedByFresh('passkeys');
});

it('TC-311-23: migrate:rollback で passkeys が消え、もう一度 migrate すると戻る', function () {
    MigrationRoundTrip::assertRolledBackAndRestored('passkeys');
});
