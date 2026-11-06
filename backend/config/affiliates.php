<?php

/*
|--------------------------------------------------------------------------
| アフィリエイトの商品の収集(affiliates:collect)の間隔と件数
|--------------------------------------------------------------------------
|
| 既定値は docs/decisions/affiliate-freshness.md の 5(#247 の目安)。定期実行(routes/console.php)と、
| --limit を省いたときの affiliates:collect が読む(#249)。
|
*/

return [
    // 定期実行の間隔(分)。取り直し(--refresh)と新しく探す(--new)を、この間隔で 1 つのコマンドから順に流す。
    // 1〜59 の整数でなければ、既定の 10 分にする
    'interval_minutes' => env('AFFILIATES_COLLECT_INTERVAL_MINUTES', 10),

    // 1 回に取り直す件数。Amazon・楽天それぞれの件数(Amazon は 10 件ごとに GetItems を 1 回、楽天は 1 件ずつ)
    'refresh_limit' => env('AFFILIATES_REFRESH_LIMIT', 10),

    // 1 回に新しく探す件数(#248)
    'new_limit' => env('AFFILIATES_NEW_LIMIT', 5),
];
