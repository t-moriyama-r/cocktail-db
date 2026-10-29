<?php

use Tests\Support\MigrationRoundTrip;

// 非常用のログイン URL の記録の表(#115)のマイグレーションを、migrate:fresh・migrate:rollback で通して確かめる。

uses()->group('db');

afterEach(fn () => MigrationRoundTrip::restore());

it('TC-115-19: migrate:fresh で admin_login_links が作られる', function () {
    MigrationRoundTrip::assertCreatedByFresh('admin_login_links');
});

it('TC-115-20: migrate:rollback で admin_login_links が消え、もう一度 migrate すると戻る', function () {
    MigrationRoundTrip::assertRolledBackAndRestored('admin_login_links');
});
