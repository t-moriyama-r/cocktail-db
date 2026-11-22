<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// アフィリエイトの商品の取り直し(--refresh。#249)と、新しく探す(--new。#248)。間隔は config/affiliates.php
// (既定は 10 分ごと。docs/decisions/affiliate-freshness.md の 5)。楽天の 1 秒に 1 回を守るため、2 つを別々に登録せず、
// 1 つのコマンドで順に流す。前の回が終わっていなければ重ねて流さない(落ちたときに止まり続けないよう、印は 30 分で切れる)。
// サーバーの cron から schedule:run を呼ぶのは #75。
$interval = filter_var(config('affiliates.interval_minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 59]]);
if ($interval === false) {
    $interval = 10;
}
Schedule::command('affiliates:collect --refresh --new')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping(30);
