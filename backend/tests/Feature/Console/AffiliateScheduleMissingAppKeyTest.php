<?php

use App\Support\Settings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

// APP_KEY が空で、収集の間隔(#826)を画面で保存した行があるときの、routes/console.php の読み込み(#840)。
// routes/console.php は artisan のどのコマンドでも読まれる(デプロイの key:generate・migrate も)ので、
// 保存した値を復号できなくても落とさず、.env の間隔を使う。旧には設定の画面が無い(旧に無い)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    config()->set('settings', array_merge(config()->array('settings'), ['affiliates.interval_minutes' => '20']));
    // APP_KEY があるうちに、画面で保存したのと同じ形(暗号化した値)で行を入れておく
    app(Settings::class)->set('affiliates.interval_minutes', '15');

    // .env の APP_KEY が空のときと同じにする。作り済みの暗号化の部品を捨て、次に使うときに空のキーで作らせる
    config()->set('app.key', '');
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    Model::encryptUsing(null);
});

it('TC-840-01: APP_KEY が空で間隔を保存した行があっても、routes/console.php を読むと落ちずに .env の間隔で登録し、ログに残す', function () {
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

it('TC-840-02: APP_KEY が空で間隔を保存した行があっても、key:generate --show が流れてキーを出す', function () {
    // 起動済みの artisan は routes/console.php を読み終えているので、作り直して、コマンドを流す前に読み直させる
    app()->forgetInstance(Kernel::class);
    $kernel = app(Kernel::class);

    expect($kernel->call('key:generate', ['--show' => true]))->toBe(0)
        ->and($kernel->output())->toMatch('/^base64:\S+$/m');
});

it('TC-840-03: APP_KEY が空で保存した値を復号できないときは、設定の読み出しは保存した値が無いものとして .env の値と出どころ env を返す', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::any(), ['key' => 'affiliates.interval_minutes']);

    $resolved = app(Settings::class)->resolve('affiliates.interval_minutes');

    expect($resolved->value)->toBe('20')
        ->and($resolved->source)->toBe('env');
});
