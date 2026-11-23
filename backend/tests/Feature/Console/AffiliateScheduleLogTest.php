<?php

use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Symfony\Component\Finder\SplFileInfo;

// scheduler から動かした affiliates:collect --refresh --new の出力(件数の行)を、日ごとのログのファイルに残す(#778)。
// 旧は scheduler の出力をファイルに残していなかった(旧の scheduler は手元で動かしていないので、旧での結果は「旧に無い」)。
// 置き場所(storage)は、テストごとに作る一時ディレクトリに差し替える。

uses(RefreshDatabase::class)->group('db');

/** 架空の認証情報。対象の行が 0 件なので、Amazon・楽天には問い合わせない */
const SCHEDULE_LOG_CREDENTIALS = [
    'AMAZON_CREDENTIAL_ID' => 'test-credential-id',
    'AMAZON_CREDENTIAL_SECRET' => 'test-credential-secret',
    'AMAZON_PARTNER_TAG' => 'example-22',
    'RAKUTEN_APPLICATION_ID' => 'test-application-id',
    'RAKUTEN_ACCESS_KEY' => 'test-access-key',
];

beforeEach(function () {
    $storage = sys_get_temp_dir().'/affiliate-schedule-log-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($storage.'/logs');
    app()->useStoragePath($storage);
});

afterEach(function () {
    // 差し替えた一時ディレクトリだけを消す
    if (str_contains(storage_path(), 'affiliate-schedule-log-')) {
        File::deleteDirectory(storage_path());
    }
});

/**
 * routes/console.php を読み直し、登録された affiliates:collect の予定を返す。
 */
function scheduleAffiliateLogEvent(): Event
{
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

/**
 * scheduler が動かすのと同じように、予定を動かす(別のプロセスで php artisan affiliates:collect を流す)。
 * 子のプロセスにだけ認証情報を渡し、終わったら消す(後のテストの設定に残さない)。
 */
function runAffiliateLogEvent(Event $event): void
{
    try {
        foreach (SCHEDULE_LOG_CREDENTIALS as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        $event->run(app());
    } finally {
        foreach (array_keys(SCHEDULE_LOG_CREDENTIALS) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
    expect($event->exitCode)->toBe(0);
}

it('TC-778-01: scheduler から動かした回の件数の行が、その日のログのファイルに、動かした時刻の見出しの後に残る', function () {
    // 対象の行が無いこと(あれば Amazon・楽天に問い合わせてしまう)
    expect(Brand::query()->count() + Category::query()->count() + Affiliate::query()->count())->toBe(0);
    $this->travelTo(Carbon::parse('2026-11-23 12:00:00'));

    runAffiliateLogEvent(scheduleAffiliateLogEvent());

    $lines = file(storage_path('logs/affiliates-collect-2026-11-23.log'), FILE_IGNORE_NEW_LINES);
    expect($lines)->toBe([
        '[2026-11-23 12:00:00] affiliates:collect --refresh --new',
        '取り直した(Amazon): 0 件(保存 0・ID を消した 0(うち行を消した 0)・失敗 0・次の回にまわした 0)',
        '取り直した(楽天): 0 件(保存 0・ID を消した 0(うち行を消した 0)・失敗 0・次の回にまわした 0)',
        '探した: 0 件(保存 0・行ができていた 0・失敗 0・次の回にまわした 0)',
        '探し直した: 0 件(保存 0・行が変わっていた 0・次の回にまわした 0)',
    ]);
});

it('TC-778-02: 同じ日の 2 回目は、前の回の行を消さずに後ろに足す', function () {
    expect(Brand::query()->count() + Category::query()->count() + Affiliate::query()->count())->toBe(0);
    $path = storage_path('logs/affiliates-collect-2026-11-23.log');
    File::put($path, "[2026-11-23 11:50:00] affiliates:collect --refresh --new\n前の回の行\n");
    $this->travelTo(Carbon::parse('2026-11-23 12:00:00'));

    runAffiliateLogEvent(scheduleAffiliateLogEvent());

    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    expect(array_slice($lines, 0, 3))->toBe([
        '[2026-11-23 11:50:00] affiliates:collect --refresh --new',
        '前の回の行',
        '[2026-11-23 12:00:00] affiliates:collect --refresh --new',
    ])->and($lines)->toHaveCount(7);
});

it('TC-778-03: ファイルは日ごとに分け、日付の変わった後の回は新しい日のファイルに足す', function () {
    $this->travelTo(Carbon::parse('2026-11-24 00:00:00'));

    $event = scheduleAffiliateLogEvent();

    expect($event->output)->toBe(storage_path('logs/affiliates-collect-2026-11-24.log'))
        ->and($event->shouldAppendOutput)->toBeTrue();
});

it('TC-778-04: 動かす前に、日付が 14 日より前のファイルを消す。13 日前までと、形の違う名前のファイルは残す', function () {
    $logs = storage_path('logs');
    foreach (['affiliates-collect-2026-11-09.log', 'affiliates-collect-2026-11-10.log', 'affiliates-collect-2026-11-22.log', 'affiliates-collect-old.log', 'laravel.log', 'schedule-run.log'] as $name) {
        File::put("{$logs}/{$name}", "x\n");
    }
    $this->travelTo(Carbon::parse('2026-11-23 12:00:00'));

    // 消すのと見出しを書くのは、コマンドを動かす前の処理(コマンドそのものは TC-778-01 で動かす)
    scheduleAffiliateLogEvent()->callBeforeCallbacks(app());

    $names = array_map(fn (SplFileInfo $file) => $file->getFilename(), File::files($logs));
    sort($names);
    expect($names)->toBe([
        'affiliates-collect-2026-11-10.log',
        'affiliates-collect-2026-11-22.log',
        'affiliates-collect-2026-11-23.log',
        'affiliates-collect-old.log',
        'laravel.log',
        'schedule-run.log',
    ])->and(File::get("{$logs}/affiliates-collect-2026-11-23.log"))->toBe("[2026-11-23 12:00:00] affiliates:collect --refresh --new\n");
});
