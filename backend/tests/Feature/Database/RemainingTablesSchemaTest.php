<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaShape;

// 辞書・言い換え・スキップ語・技法・氷・アフィリエイト・設定の表(#23)の形を docs/schema.md と突き合わせる。

uses(RefreshDatabase::class)->group('db');

it('TC-23-03: methods は旧 m_method と同じ列(と、#40 で足した用語への列)で、作成・更新の時刻を持たない', function () {
    expect(SchemaShape::columns('methods'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', false, null],
        'description' => ['text', false, null],
        'dictionary_id' => ['bigint(20) unsigned', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('methods'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'methods_dictionary_id_foreign' => ['dictionary_id'],
        ]);
});

it('TC-40-21: methods.dictionary_id は用語辞典を指し、指された用語は消せない(RESTRICT)', function () {
    expect(SchemaShape::foreignKeys('methods'))->toBe([
        ['dictionary_id', 'dictionaries', 'id', 'restrict'],
    ]);
});

it('TC-23-04: ices は旧 m_ice と同じ列で、英名だけ null 可', function () {
    expect(SchemaShape::columns('ices'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('ices'))->toBe(['primary' => ['id']]);
});

it('TC-23-05: dictionary_categories は旧 m_dictionary_category と同じ列', function () {
    expect(SchemaShape::columns('dictionary_categories'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', false, null],
    ])
        ->and(SchemaShape::indexes('dictionary_categories'))->toBe(['primary' => ['id']]);
});

it('TC-23-06: dictionaries の列・索引・外部キーが設計どおり', function () {
    expect(SchemaShape::columns('dictionaries'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'dictionary_category_id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'name_eng' => ['varchar(255)', false, null],
        'name_search' => ['varchar(255)', false, null],
        'description' => ['text', false, null],
        'youtube' => ['varchar(255)', true, 'NULL'],
        'youtube_start_seconds' => ['int(10) unsigned', true, 'NULL'],
        'image' => ['varchar(255)', true, 'NULL'],
        'image_txt' => ['varchar(255)', true, 'NULL'],
        'image_url' => ['text', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('dictionaries'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'dictionaries_dictionary_category_id_foreign' => ['dictionary_category_id'],
            'dictionaries_name_search_index' => ['name_search'],
        ])
        ->and(SchemaShape::foreignKeys('dictionaries'))->toBe([
            ['dictionary_category_id', 'dictionary_categories', 'id', 'restrict'],
        ]);
});

it('TC-23-07: paraphrases は旧の列に id と時刻を足し、name が unique', function () {
    expect(SchemaShape::columns('paraphrases'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(255)', false, null],
        'converted' => ['varchar(255)', false, null],
        'middle' => ['varchar(255)', true, 'NULL'],
        'note' => ['text', true, 'NULL'],
        'command' => ['varchar(255)', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('paraphrases'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'paraphrases_name_unique' => ['name'],
        ]);
});

it('TC-23-08: skip_words は旧の列に id と時刻を足し、name(256 文字)が unique', function () {
    expect(SchemaShape::columns('skip_words'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'name' => ['varchar(256)', false, null],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('skip_words'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'skip_words_name_unique' => ['name'],
        ]);
});

it('TC-23-09: settings の列・unique・外部キー(変えた人を消すと null)が設計どおり', function () {
    expect(SchemaShape::columns('settings'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'key' => ['varchar(100)', false, null],
        'value' => ['text', true, 'NULL'],
        'updated_by' => ['bigint(20) unsigned', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ])
        ->and(SchemaShape::indexes('settings'))->toEqualCanonicalizing([
            'primary' => ['id'],
            'settings_key_unique' => ['key'],
            'settings_updated_by_foreign' => ['updated_by'],
        ])
        ->and(SchemaShape::foreignKeys('settings'))->toBe([
            ['updated_by', 'users', 'id', 'set null'],
        ]);
});

it('TC-23-10: affiliates の列の型・null 可が設計どおり(#247 の時刻と商品 ID の列を含む)', function () {
    expect(SchemaShape::columns('affiliates'))->toBe([
        'id' => ['bigint(20) unsigned', false, null],
        'category_id' => ['bigint(20) unsigned', false, null],
        'brand_id' => ['bigint(20) unsigned', true, 'NULL'],
        // 生成列は MariaDB が null 可と報告する。IFNULL で作るので、値は null にならない(TC-23-21・23・24)
        'brand_key' => ['bigint(20) unsigned', true, 'NULL'],
        'amazon_asin' => ['varchar(10)', true, 'NULL'],
        'amazon_title' => ['text', true, 'NULL'],
        'amazon_price' => ['int(10) unsigned', true, 'NULL'],
        'amazon_image_large_url' => ['text', true, 'NULL'],
        'amazon_image_medium_url' => ['text', true, 'NULL'],
        'amazon_image_small_url' => ['text', true, 'NULL'],
        'amazon_detail_url' => ['text', true, 'NULL'],
        'amazon_search_url' => ['text', true, 'NULL'],
        'amazon_fetched_at' => ['datetime', true, 'NULL'],
        'rakuten_item_code' => ['varchar(255)', true, 'NULL'],
        'rakuten_title' => ['text', true, 'NULL'],
        'rakuten_price' => ['int(10) unsigned', true, 'NULL'],
        'rakuten_image_url' => ['text', true, 'NULL'],
        'rakuten_detail_url' => ['text', true, 'NULL'],
        'rakuten_search_url' => ['text', true, 'NULL'],
        'rakuten_fetched_at' => ['datetime', true, 'NULL'],
        'human_title' => ['varchar(256)', true, 'NULL'],
        'human_price' => ['int(10) unsigned', true, 'NULL'],
        'human_image_url' => ['text', true, 'NULL'],
        'human_amazon_detail_url' => ['text', true, 'NULL'],
        'human_amazon_search_url' => ['text', true, 'NULL'],
        'last_viewed_at' => ['datetime', true, 'NULL'],
        'created_at' => ['timestamp', true, 'NULL'],
        'updated_at' => ['timestamp', true, 'NULL'],
    ]);
});

it('TC-23-11: affiliates の索引は (category_id, brand_key) の unique・brand_id・last_viewed_at で、外部キーは categories と brands', function () {
    expect(SchemaShape::indexes('affiliates'))->toEqualCanonicalizing([
        'primary' => ['id'],
        'affiliates_category_id_brand_key_unique' => ['category_id', 'brand_key'],
        'affiliates_brand_id_index' => ['brand_id'],
        'affiliates_last_viewed_at_index' => ['last_viewed_at'],
    ])
        // brand_id の外部キー(brands への CASCADE)は、brands(#22)と両方そろった後に #325 で足した(TC-325-02)
        ->and(SchemaShape::foreignKeys('affiliates'))->toEqualCanonicalizing([
            ['category_id', 'categories', 'id', 'restrict'],
            ['brand_id', 'brands', 'id', 'cascade'],
        ]);
});

it('TC-23-12: users は Laravel の雛形のまま(旧の twitter_id を持たない)', function () {
    expect(Schema::getColumnListing('users'))->toBe([
        'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at',
    ]);
});
