<?php

namespace App\Services\Affiliate;

use App\Models\Category;
use App\Services\CategoryTree;
use App\Support\Settings;
use InvalidArgumentException;

/**
 * ノード・銘柄の名前から、Amazon・楽天の検索結果のページへのリンクを組み立てる。
 *
 * 期限(AffiliateFreshness)を過ぎた商品は、API から取った検索結果のリンクも出せないので、名前から組み立てたリンクを
 * 代わりに出す(docs/decisions/affiliate-freshness.md の 4)。パートナータグ・アフィリエイトの ID は、画面から変える
 * 設定(App\Support\Settings。#69)から読み、無いあいだは付けずに検索結果のページへ直接リンクする。
 *
 * 楽天で絞るジャンル(rakutenGenreId)もここで決める。名前で商品を探す(AffiliateCollector)ときと、検索結果のページへの
 * リンクとで、探す範囲を揃えるため(#827)。
 */
final class AffiliateSearchUrls
{
    public const SETTING_RAKUTEN_AFFILIATE_ID = 'rakuten.affiliate_id';

    public function __construct(
        private readonly Settings $settings,
        private readonly CategoryTree $tree,
    ) {}

    /**
     * そのノード(銘柄なら、ぶら下がるノード)の商品を楽天で探すときに絞るジャンル。洋酒の材料はジャンル「ビール・洋酒」
     * (RakutenClient::LIQUOR_GENRE_ID)、洋酒でない材料(根の役割がソフトドリンク・その他の部分木。CategoryTree::isNonLiquor)
     * は null(ジャンルで絞らない)。
     *
     * 旧は「洋酒ではない」の判定が効かず、いつも 510915 で絞っていた。洋酒でない材料に合わない商品(バターに赤ワインなど)が
     * 付くので、旧のコードが意図していた動きに戻す(#827。docs/decisions/category-root-role.md の「洋酒ではない
     * (kind 4 以上)は、本番では効いていない」)。木に入っていないノード(閉包表に行の無い壊れたデータ)は、根の役割を
     * 引けないので、旧と同じく絞る。
     */
    public function rakutenGenreId(Category $node): ?string
    {
        try {
            return $this->tree->isNonLiquor($node) ? null : RakutenClient::LIQUOR_GENRE_ID;
        } catch (InvalidArgumentException) {
            return RakutenClient::LIQUOR_GENRE_ID;
        }
    }

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
     * 楽天市場の検索結果のページ。$node(銘柄なら、ぶら下がるノード)で絞るジャンル(rakutenGenreId)を、旧
     * (Rakuten::set_searchURL)と同じく語の後ろのパスに付ける。洋酒でない材料ではジャンルを付けない(#827)。
     * アフィリエイトの ID があれば、旧と同じ形でアフィリエイトのリンク(hb.afl.rakuten.co.jp)に包む。
     */
    public function rakuten(string $name, Category $node): string
    {
        $genreId = $this->rakutenGenreId($node);
        $genrePath = $genreId !== null ? $genreId.'/' : '';
        $affiliateId = $this->settings->get(self::SETTING_RAKUTEN_AFFILIATE_ID);
        if (blank($affiliateId)) {
            return 'https://search.rakuten.co.jp/search/mall/'.rawurlencode($name).'/'.$genrePath;
        }

        // 旧と同じく、検索語は 1 回だけエンコードし、前後の URL の部分は pc= の値としてエンコードしておく
        return 'https://hb.afl.rakuten.co.jp/hgc/'.rawurlencode($affiliateId).'/?pc='
            .rawurlencode('https://search.rakuten.co.jp/search/mall/')
            .urlencode($name)
            .rawurlencode('/'.$genrePath);
    }
}
