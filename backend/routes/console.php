<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// アフィリエイトの商品の取り直し(--refresh。#249)と、新しく探す(--new。#248)。間隔は config/affiliates.php
// (既定は 10 分ごと。docs/decisions/affiliate-freshness.md の 5)。楽天の 1 秒に 1 回を守るため、2 つを別々に登録せず、
// 1 つのコマンドで順に流す。前の回が終わっていなければ重ねて流さない(落ちたときに止まり続けないよう、印は 30 分で切れる)。
// サーバーの cron から schedule:run を呼ぶのは #75。
// scheduler は、動かしたコマンドの出力を既定で捨てる。件数の行を確かめられるよう、出力を日ごとのファイル
// (storage/logs/affiliates-collect-<YYYY-MM-DD>.log)に足していく。回ごとに、動かした時刻の見出しの行を先に書く。
// 大きくなりすぎないよう、動かす前に、日付が 14 日より前のファイルを消す(Laravel の daily のログと同じ日数。#778)。
$interval = filter_var(config('affiliates.interval_minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 59]]);
if ($interval === false) {
    $interval = 10;
}
// 見出しと出力が日をまたいで別のファイルに分かれないよう、ファイルの名前は登録するとき(schedule:run の回ごと)に 1 度だけ決める
$collectDate = Carbon::today();
$collectLog = storage_path('logs/affiliates-collect-'.$collectDate->toDateString().'.log');
$collectKeepFrom = $collectDate->copy()->subDays(13)->toDateString();
Schedule::command('affiliates:collect --refresh --new')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping(30)
    ->before(function () use ($collectLog, $collectKeepFrom) {
        foreach (File::glob(storage_path('logs/affiliates-collect-*.log')) as $path) {
            if (is_string($path) && preg_match('/affiliates-collect-(\d{4}-\d{2}-\d{2})\.log\z/', $path, $matches) === 1 && $matches[1] < $collectKeepFrom) {
                File::delete($path);
            }
        }
        File::append($collectLog, '['.Carbon::now()->toDateTimeString().'] affiliates:collect --refresh --new'.PHP_EOL);
    })
    ->appendOutputTo($collectLog);
