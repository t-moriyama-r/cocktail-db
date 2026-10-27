<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * マイグレーションを migrate:fresh・migrate:rollback で往復させ、表ができる・消える・戻ることを確かめる。
 *
 * DDL は MariaDB で暗黙にコミットされるので RefreshDatabase の中では往復できない。往復させたテストは、
 * 終わりに restore() で全部をマイグレーションし直し、ほかのテストファイル(RefreshDatabase は一度
 * マイグレーションした後は作り直さない)に、表の無い状態を残さないようにする。
 */
final class MigrationRoundTrip
{
    public static function assertCreatedByFresh(string $table): void
    {
        expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0)
            ->and(Schema::hasTable($table))->toBeTrue();
    }

    public static function assertRolledBackAndRestored(string $table): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0)
            ->and(Schema::hasTable($table))->toBeFalse()
            ->and(DB::table('migrations')->count())->toBe(0);

        expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
            ->and(Schema::hasTable($table))->toBeTrue();
    }

    public static function restore(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }
}
