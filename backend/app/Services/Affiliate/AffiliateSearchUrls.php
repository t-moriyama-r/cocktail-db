<?php

namespace App\Services\Affiliate;

use App\Support\Settings;

/**
 * ノード・銘柄の名前から、Amazon・楽天の検索結果のページへのリンクを組み立てる。
 *
 * 期限(AffiliateFreshness)を過ぎた商品は、API から取った検索結果のリンクも出せないので、名前から組み立てたリンクを
 * 代わりに出す(docs/decisions/affiliate-freshness.md の 4)。パートナータグ・アフィリエイトの ID は、画面から変える
 * 設定(App\Support\Settings。#69)から読み、無いあいだは付けずに検索結果のページへ直接リンクする。
 */
final class AffiliateSearchUrls
{
    public const SETTING_RAKUTEN_AFFILIATE_ID = 'rakuten.affiliate_id';

    /** 旧(Rakuten::set_searchURL)がいつも付けていた、楽天市場のジャンル「ビール・洋酒」 */
    private const RAKUTEN_GENRE_ID = '510915';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Amazon の検索結果のページ。パートナータグがあれば `tag` に付ける。
     */
    public function amazon(string $name): string
    {
        $query = ['k' => $name];
        $tag = $this->settings->get(AmazonClient::SETTING_PARTNER_TAG);
        if (filled($tag)) {
            $query['tag'] = $tag;
        }

        return 'https://www.amazon.co.jp/s?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 楽天市場の検索結果のページ。アフィリエイトの ID があれば、旧(Rakuten::set_searchURL)と同じ形で
     * アフィリエイトのリンク(hb.afl.rakuten.co.jp)に包む。
     */
    public function rakuten(string $name): string
    {
        $affiliateId = $this->settings->get(self::SETTING_RAKUTEN_AFFILIATE_ID);
        if (blank($affiliateId)) {
            return 'https://search.rakuten.co.jp/search/mall/'.rawurlencode($name).'/'.self::RAKUTEN_GENRE_ID.'/';
        }

        // 旧と同じく、検索語は 1 回だけエンコードし、前後の URL の部分は pc= の値としてエンコードしておく
        return 'https://hb.afl.rakuten.co.jp/hgc/'.rawurlencode($affiliateId).'/?pc='
            .rawurlencode('https://search.rakuten.co.jp/search/mall/')
            .urlencode($name)
            .rawurlencode('/'.self::RAKUTEN_GENRE_ID.'/');
    }
}
