<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchemaShape;

// 管理画面のパスキーの表(#311)の形を docs/schema.md と突き合わせる。

uses(RefreshDatabase::class)->group('db');

it('TC-311-21: passkeys の列・unique・外部キー(ユーザーを消すと消える)が設計どおり', function () {
    expect(SchemaShape::columns('passkeys'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'user_id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'credential_id' => ['varchar(255)', false, null],
        'credential' => ['longtext', false, null],
        'last_used_at' => ['timestamp', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('passkeys'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'passkeys_credential_id_unique' => ['credential_id'],
            'passkeys_user_id_foreign' => ['user_id'],
        ])
        ->and(SchemaShape::foreignKeys('passkeys'))->toBe([
            ['user_id', 'users', 'id', 'cascade'],
        ]);
});
