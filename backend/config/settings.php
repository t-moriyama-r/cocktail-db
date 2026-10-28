<?php

/*
|--------------------------------------------------------------------------
| 画面から変える設定の .env の値
|--------------------------------------------------------------------------
|
| App\Support\Settings が、画面で保存した値が無いときに使う値。キーは settings 表の key と同じにする。
| 画面で変えられる項目の定義(説明・秘密かどうか・検証の規則)は #132 で作る。
|
*/

return [
    // Amazon Creators API(App\Services\Affiliate\AmazonClient。docs/amazon-creators-api.md の 3・4)
    'amazon.credential_id' => env('AMAZON_CREDENTIAL_ID'),
    'amazon.credential_secret' => env('AMAZON_CREDENTIAL_SECRET'),
    'amazon.partner_tag' => env('AMAZON_PARTNER_TAG'),

    // 楽天アフィリエイトの ID(App\Services\Affiliate\AffiliateSearchUrls が、検索結果のページへのリンクに付ける)
    'rakuten.affiliate_id' => env('RAKUTEN_AFFILIATE_ID'),
];
