<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

// 管理画面の設定で変えた収集の間隔(#826。docs/decisions/settings.md)で、affiliates:collect が scheduler に登録される。
// schedule:run は回ごとに別のプロセスで routes/console.php を読むので、ここでは読み直して「次の schedule:run」に見立てる。
// 旧には設定の画面が無く、収集は毎分動いていた(旧に無い)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * routes/console.php を読み直し(次の schedule:run と同じ)、登録された affiliates:collect の予定の cron の式を返す。
 */
function nextScheduledCollectExpression(): string
{
    $schedule = new Schedule(config('app.timezone'));
    // 読み直した分だけを見るよう、空の Schedule に差し替えてから読む
    ScheduleFacade::swap($schedule);
    require base_path('routes/console.php');

    $events = array_values(array_filter(
        $schedule->events(),
        fn (Event $event) => str_contains((string) $event->command, 'affiliates:collect'),
    ));
    expect($events)->toHaveCount(1);

    return $events[0]->expression;
}

it('TC-826-04: 設定画面(API)で間隔を保存すると、次の schedule:run から、.env の値ではなくその間隔で登録される(schedule:list にも出る)', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['affiliates.interval_minutes' => '20']));

    $this->putJson('/api/admin/settings', ['key' => 'affiliates.interval_minutes', 'value' => '15'])->assertOk();

    expect(nextScheduledCollectExpression())->toBe('*/15 * * * *');
    expect(Artisan::call('schedule:list'))->toBe(0)
        ->and(Artisan::output())->toMatch('/\*\/15\s+\*\s+\*\s+\*\s+\*\s+php artisan affiliates:collect --refresh --new/');
});

it('TC-826-05: DB に間隔が無いとき・画面で消した(null)ときは、.env の間隔で登録される', function () {
    config()->set('settings', array_merge(config()->array('settings'), ['affiliates.interval_minutes' => '20']));

    expect(nextScheduledCollectExpression())->toBe('*/20 * * * *');

    $this->putJson('/api/admin/settings', ['key' => 'affiliates.interval_minutes', 'value' => '15'])->assertOk();
    $this->putJson('/api/admin/settings', ['key' => 'affiliates.interval_minutes', 'value' => null])
        ->assertOk()
        ->assertJsonPath('data.value', '20')
        ->assertJsonPath('data.source', 'env');

    expect(nextScheduledCollectExpression())->toBe('*/20 * * * *');
});
