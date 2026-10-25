<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// 本番と同じ MariaDB で動かすテスト。DB が無い手元では --exclude-group=db で飛ばす。CI では飛ばさない。

uses(RefreshDatabase::class)->group('db');

it('TC-6-09: テストの DB が SQLite ではなく MariaDB である', function () {
    expect(DB::connection()->getDriverName())->toBe('mariadb');
});

it('TC-6-10: マイグレーションした表に書き込んで読み戻せる', function () {
    $user = User::factory()->create(['name' => 'テスト管理者']);

    expect(User::findOrFail($user->id)->name)->toBe('テスト管理者');
});

it('TC-6-11: 4 バイト文字(絵文字)が欠けずに保存される', function () {
    $user = User::factory()->create(['name' => '🍸カクテル𩸽']);

    expect(User::findOrFail($user->id)->name)->toBe('🍸カクテル𩸽');
});
