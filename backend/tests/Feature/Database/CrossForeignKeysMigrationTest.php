<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MigrationRoundTrip;
use Tests\Support\SchemaShape;

// 銘柄・カクテルと技法・氷・用語辞典・アフィリエイトのあいだの外部キーを足すマイグレーション(#325)を、
// migrate:fresh・migrate:rollback で通して確かめる。

uses()->group('db');

afterEach(fn () => MigrationRoundTrip::restore());

it('TC-325-10: migrate:fresh で外部キーが張られる', function () {
    MigrationRoundTrip::assertCreatedByFresh('affiliates');

    expect(SchemaShape::foreignKeys('affiliates'))->toContain(['brand_id', 'brands', 'id', 'cascade'])
        ->and(SchemaShape::foreignKeys('cocktails'))->toContain(['ice_id', 'ices', 'id', 'restrict']);
});

it('TC-325-11: migrate:rollback で全部の表が消え、もう一度 migrate すると戻る', function () {
    MigrationRoundTrip::assertRolledBackAndRestored('cocktails');
});

it('TC-325-12: このマイグレーションだけを戻すと、足した外部キーと ice_id の索引だけが消え、表と元の外部キーは残る', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $migration = '2026_10_27_300000_add_cross_foreign_keys_to_cocktails_and_affiliates_tables';
    $steps = DB::table('migrations')->where('migration', '>=', $migration)->count();

    expect(Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]))->toBe(0)
        ->and(Schema::hasTable('cocktails'))->toBeTrue()
        ->and(Schema::hasTable('affiliates'))->toBeTrue()
        ->and(SchemaShape::foreignKeys('cocktails'))->toBe([['base_category_id', 'categories', 'id', 'restrict']])
        ->and(SchemaShape::foreignKeys('affiliates'))->toBe([['category_id', 'categories', 'id', 'restrict']])
        ->and(Schema::hasIndex('cocktails', ['ice_id']))->toBeFalse()
        ->and(Schema::hasIndex('cocktails', ['method_id']))->toBeTrue()
        ->and(Schema::hasIndex('affiliates', ['brand_id']))->toBeTrue();
});
