<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchemaShape;

// 名前で商品を探して失敗したノード・銘柄の表(affiliate_collect_failures。#695)の形を docs/schema.md と突き合わせる。
// 失敗した件を選ばない振る舞いは tests/Feature/Console/CollectNewAffiliatesTest.php の TC-695-01〜09。

uses(RefreshDatabase::class)->group('db');

it('TC-695-10: affiliate_collect_failures の列・索引・外部キーが docs/schema.md のとおり', function () {
    expect(SchemaShape::columns('affiliate_collect_failures'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'category_id' => ['bigint(20) unsigned', false, null],
        'brand_id' => ['bigint(20) unsigned', true, 'NULL'],
        // 生成列は MariaDB が null 可と報告する。IFNULL で作るので、値は null にならない
        'brand_key' => ['bigint(20) unsigned', true, 'NULL'],
        'name' => ['varchar(255)', false, null],
        'failure_count' => ['int(10) unsigned', false, null],
        'last_message' => ['text', true, 'NULL'],
        'retry_after' => ['datetime', false, null],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('affiliate_collect_failures'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'affiliate_collect_failures_category_id_brand_key_unique' => ['category_id', 'brand_key'],
            'affiliate_collect_failures_brand_id_foreign' => ['brand_id'],
            'affiliate_collect_failures_retry_after_index' => ['retry_after'],
        ])
        ->and(SchemaShape::foreignKeys('affiliate_collect_failures'))->toEqualCanonicalizing([
            ['category_id', 'categories', 'id', 'cascade'],
            ['brand_id', 'brands', 'id', 'cascade'],
        ]);
});
