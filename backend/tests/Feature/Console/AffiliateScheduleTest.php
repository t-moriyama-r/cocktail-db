<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

// アフィリエイトの商品の取り直し(--refresh。#249)と新しく探す(--new。#248)を、Laravel の scheduler に登録する(#249)。
// 間隔は設定(画面で保存した値、無ければ .env の値。#826)から読み、既定は 10 分ごと(docs/decisions/affiliate-freshness.md の 5)。
// ここでは .env の値(config/settings.php)だけを変える。画面で保存した値は AffiliateCollectSettingsTest(#826)。
// 旧は routes/console.php で AffiliateController@main を毎分呼んでいた(旧ソースで確かめた。旧の scheduler は手元で動かしていない)。

/**
 * routes/console.php を .env の間隔 $interval で読み直し、登録された affiliates:collect の予定を返す。
 */
function scheduleAffiliateEvent(mixed $interval): Event
{
    config()->set('settings', array_merge(config()->array('settings'), ['affiliates.interval_minutes' => $interval]));
    $schedule = new Schedule;
    // 読み直した分だけを見るよう、空の Schedule に差し替えてから読む
    ScheduleFacade::swap($schedule);
    require base_path('routes/console.php');

    $events = array_values(array_filter(
        $schedule->events(),
        fn (Event $event) => str_contains((string) $event->command, 'affiliates:collect'),
    ));
    expect($events)->toHaveCount(1);

    return $events[0];
}

it('TC-249-20: php artisan schedule:list に、affiliates:collect --refresh --new が 10 分ごとで出る', function () {
    $exitCode = Artisan::call('schedule:list');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toMatch('/\*\/10\s+\*\s+\*\s+\*\s+\*\s+php artisan affiliates:collect --refresh --new/');
});

it('TC-249-21: 間隔は設定から読み、1〜59 の整数でなければ既定の 10 分にする。前の回が終わっていなければ重ねて流さない', function (mixed $interval, string $expression) {
    $event = scheduleAffiliateEvent($interval);

    expect($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);
})->with([
    '15 分' => [15, '*/15 * * * *'],
    '文字の 5' => ['5', '*/5 * * * *'],
    '0' => [0, '*/10 * * * *'],
    '60' => [60, '*/10 * * * *'],
    'abc' => ['abc', '*/10 * * * *'],
    '無い' => [null, '*/10 * * * *'],
]);

it('TC-826-12: 排他の名前は間隔に依存せず、画面で間隔を変えても実行中の収集に重ねて流さない', function () {
    expect(scheduleAffiliateEvent(10)->mutexName())->toBe(scheduleAffiliateEvent(1)->mutexName());
});
