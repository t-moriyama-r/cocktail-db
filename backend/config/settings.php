<?php

/*
|--------------------------------------------------------------------------
| 画面から変える設定の .env の値
|--------------------------------------------------------------------------
|
| App\Support\Settings が、画面で保存した値が無いときに使う値。キーは settings 表の key と同じにする。
| 画面で変えられる項目の定義(説明・秘密かどうか・検証の規則)は App\Support\SettingDefinitions(#132)。
| 項目を足すときは、ここと SettingDefinitions の両方に足す。
|
*/

return [
    // Amazon Creators API(App\Services\Affiliate\AmazonClient。docs/amazon-creators-api.md の 3・4)
    'amazon.credential_id' => env('AMAZON_CREDENTIAL_ID'),
    'amazon.credential_secret' => env('AMAZON_CREDENTIAL_SECRET'),
    'amazon.partner_tag' => env('AMAZON_PARTNER_TAG'),

    // 楽天の商品検索 API のアプリ ID とアクセスキー(App\Services\Affiliate\RakutenClient。docs/rakuten-api.md の 2)
    'rakuten.application_id' => env('RAKUTEN_APPLICATION_ID'),
    'rakuten.access_key' => env('RAKUTEN_ACCESS_KEY'),

    // 楽天アフィリエイトの ID(App\Services\Affiliate\AffiliateSearchUrls が検索結果のページへのリンクに、
    // RakutenClient が商品検索 API の呼び出しに付ける)
    'rakuten.affiliate_id' => env('RAKUTEN_AFFILIATE_ID'),

    // アクセス解析(GA4)の測定 ID(App\Support\Ga4Tag。docs/decisions/analytics.md)。本番だけに入れる
    'ga4.measurement_id' => env('GA4_MEASUREMENT_ID'),

    // アフィリエイトの商品の収集(affiliates:collect)の間隔(分)と 1 回の件数(App\Services\Affiliate\AffiliateCollectSettings。#826)。
    // どちらにも値が無ければ、既定値(10 分・10 件・5 件。docs/decisions/affiliate-freshness.md の 5)を使う
    'affiliates.interval_minutes' => env('AFFILIATES_COLLECT_INTERVAL_MINUTES'),
    'affiliates.refresh_limit' => env('AFFILIATES_REFRESH_LIMIT'),
    'affiliates.new_limit' => env('AFFILIATES_NEW_LIMIT'),
];
