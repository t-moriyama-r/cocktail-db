<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchemaShape;

// 非常用のログイン URL の記録の表(#115)の形を docs/schema.md と突き合わせる。

uses(RefreshDatabase::class)->group('db');

it('TC-115-21: admin_login_links の列・unique・外部キー(ユーザーを消すと消える)が設計どおり', function () {
    expect(SchemaShape::columns('admin_login_links'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'user_id' => ['bigint(20) unsigned', false, null],
        'token_hash' => ['char(64)', false, null],
        'expires_at' => ['timestamp', false, null],
        'used_at' => ['timestamp', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('admin_login_links'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'admin_login_links_token_hash_unique' => ['token_hash'],
            'admin_login_links_user_id_foreign' => ['user_id'],
        ])
        ->and(SchemaShape::foreignKeys('admin_login_links'))->toBe([
            ['user_id', 'users', 'id', 'cascade'],
        ]);
});
