<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

// 設定(#69)を読めないとき(DB に繋がらない)の、affiliates:collect の間隔(#826)。routes/console.php は artisan の
// どのコマンドでも読まれる(migrate の前など)ので、設定を読めなくても落とさない。
// DB に繋がらない状態を作るので、db グループに入れない(RefreshDatabase も使わない)。旧には設定の画面が無い(旧に無い)。

beforeEach(function () {
    // 使っている接続を、誰も待ち受けていないポートに向ける。DB を引くと接続の失敗(QueryException)になる
    $connection = config()->string('database.default');
    config()->set("database.connections.{$connection}.host", '127.0.0.1');
    config()->set("database.connections.{$connection}.port", 1);
    DB::purge($connection);
});

it('TC-826-09: 設定を読めないときは、落とさずに .env の間隔で登録し、ログに残す', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['affiliates.interval_minutes' => '20']));
    Log::shouldReceive('warning')->once()->with(Mockery::any(), ['key' => 'affiliates.interval_minutes']);

    $schedule = new Schedule;
    ScheduleFacade::swap($schedule);
    require base_path('routes/console.php');

    $events = array_values(array_filter(
        $schedule->events(),
        fn (Event $event) => str_contains((string) $event->command, 'affiliates:collect'),
    ));
    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/20 * * * *');
});
