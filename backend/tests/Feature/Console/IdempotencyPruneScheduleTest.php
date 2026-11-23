<?php

use App\Models\IdempotencyKey;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

// 管理画面の保存の冪等キーの古い記録(idempotency_keys)を、Laravel の scheduler で毎日消す(#763。
// docs/decisions/admin-idempotency.md の 10)。旧に冪等キーは無い。

it('TC-763-04: scheduler に、IdempotencyKey の model:prune が毎日 19:05(UTC。日本時間の 4:05)で 1 つだけ登録される', function () {
    $schedule = new Schedule;
    // 読み直した分だけを見るよう、空の Schedule に差し替えてから読む
    ScheduleFacade::swap($schedule);
    require base_path('routes/console.php');

    $events = array_values(array_filter(
        $schedule->events(),
        fn (Event $event) => str_contains((string) $event->command, 'model:prune'),
    ));

    expect($events)->toHaveCount(1)
        ->and((string) $events[0]->command)->toContain(class_basename(IdempotencyKey::class))
        ->and($events[0]->expression)->toBe('5 19 * * *');
});
