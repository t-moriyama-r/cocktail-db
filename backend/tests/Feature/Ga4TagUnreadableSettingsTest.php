<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// 設定(#69)を読めないとき(DB に繋がらない)の、SPA の HTML の GA4 のタグ(#59)。
// DB に繋がらない状態を作るので、db グループに入れない(RefreshDatabase も使わない)。

beforeEach(function () {
    $this->withoutVite();
    // 使っている接続を、誰も待ち受けていないポートに向ける。DB を引くと接続の失敗(QueryException)になる
    $connection = config()->string('database.default');
    config()->set("database.connections.{$connection}.host", '127.0.0.1');
    config()->set("database.connections.{$connection}.port", 1);
    DB::purge($connection);
});

it('TC-59-08: 設定を読めないときは、GA4 のタグを入れずに SPA の HTML を 200 で返し、ログに残す', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::any(), ['key' => 'ga4.measurement_id']);

    $response = $this->get('/')->assertOk()->assertSee('<div id="root"></div>', false);

    expect((string) $response->getContent())->not->toContain('googletagmanager.com');
});
