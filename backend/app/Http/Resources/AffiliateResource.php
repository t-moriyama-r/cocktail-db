<?php

namespace App\Http\Resources;

use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateFreshness;
use App\Services\Affiliate\AffiliateSearchUrls;
use App\Services\Affiliate\AmazonClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 商品の欄に出すアフィリエイトの 1 件(GET /api/affiliates。#47)。
 *
 * - display: 欄の本体(画像・題名・価格・商品のページ)に出す 1 件。旧の画面(AffiliateContainer.vue の display_command)と
 *   同じく、手で入れた価格 → Amazon → 楽天の順に、価格のあるものを選ぶ。どれも無ければ null
 * - amazon・rakuten: 提供元ごとの値と、取った時刻(fetched_at)。期限(AffiliateFreshness)を過ぎた値は返さず、
 *   検索結果のリンクだけを名前から組み立てて返す(docs/decisions/affiliate-freshness.md の 2・4)。一度も商品が
 *   見つかっていない提供元は、旧と同じく検索結果のリンクも null
 * - Amazon の認証情報かパートナータグが設定に無いあいだ(AmazonClient::isConfigured() が偽)は、Creators API を
 *   使えないので、Amazon の値は期限内でも返さず、名前から組み立てた検索結果のリンクだけを返す(#136)。商品が
 *   一度も見つかっていなくても返す
 * - human: 手で入れた値。期限なしで返す
 *
 * @property Affiliate $resource
 */
class AffiliateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $affiliate = $this->resource->loadMissing(['category', 'brand']);
        $name = $affiliate->brand !== null ? $affiliate->brand->name : $affiliate->category->name;
        $searchUrls = app(AffiliateSearchUrls::class);
        $now = now();

        $amazonConfigured = app(AmazonClient::class)->isConfigured();
        $amazonFresh = $amazonConfigured && AffiliateFreshness::isFresh($affiliate->amazon_fetched_at, $now);
        $amazonFound = $affiliate->amazon_asin !== null || $affiliate->amazon_detail_url !== null;
        // 画像は、旧の画面と同じく中くらいのものを出す
        $amazon = [
            'title' => $amazonFresh ? $affiliate->amazon_title : null,
            'price' => $amazonFresh ? $affiliate->amazon_price : null,
            'image_url' => $amazonFresh ? $affiliate->amazon_image_medium_url : null,
            'detail_url' => $amazonFresh ? $affiliate->amazon_detail_url : null,
            'search_url' => $amazonFresh
                ? $affiliate->amazon_search_url
                : ($amazonFound || ! $amazonConfigured ? $searchUrls->amazon($name) : null),
            'fetched_at' => $amazonFresh ? $affiliate->amazon_fetched_at : null,
        ];

        $rakutenFresh = AffiliateFreshness::isFresh($affiliate->rakuten_fetched_at, $now);
        $rakutenFound = $affiliate->rakuten_item_code !== null || $affiliate->rakuten_detail_url !== null;
        $rakuten = [
            'title' => $rakutenFresh ? $affiliate->rakuten_title : null,
            'price' => $rakutenFresh ? $affiliate->rakuten_price : null,
            'image_url' => $rakutenFresh ? $affiliate->rakuten_image_url : null,
            'detail_url' => $rakutenFresh ? $affiliate->rakuten_detail_url : null,
            'search_url' => $rakutenFresh
                ? $affiliate->rakuten_search_url
                : ($rakutenFound ? $searchUrls->rakuten($name) : null),
            'fetched_at' => $rakutenFresh ? $affiliate->rakuten_fetched_at : null,
        ];

        $human = [
            'title' => $affiliate->human_title,
            'price' => $affiliate->human_price,
            'image_url' => $affiliate->human_image_url,
            'amazon_detail_url' => $affiliate->human_amazon_detail_url,
            'amazon_search_url' => $affiliate->human_amazon_search_url,
        ];

        // 旧の画面は価格が「真」かで選んでいた(0 は無いのと同じ)
        $display = match (true) {
            $human['price'] > 0 => [
                'source' => 'human',
                'title' => $human['title'],
                'price' => $human['price'],
                'image_url' => $human['image_url'],
                'detail_url' => $human['amazon_detail_url'],
                'fetched_at' => null,
            ],
            $amazon['price'] > 0 => [
                'source' => 'amazon',
                'title' => $amazon['title'],
                'price' => $amazon['price'],
                'image_url' => $amazon['image_url'],
                'detail_url' => $amazon['detail_url'],
                'fetched_at' => $amazon['fetched_at'],
            ],
            $rakuten['price'] > 0 => [
                'source' => 'rakuten',
                'title' => $rakuten['title'],
                'price' => $rakuten['price'],
                'image_url' => $rakuten['image_url'],
                'detail_url' => $rakuten['detail_url'],
                'fetched_at' => $rakuten['fetched_at'],
            ],
            default => null,
        };

        return [
            'category_id' => $affiliate->category_id,
            'brand_id' => $affiliate->brand_id,
            'name' => $name,
            'display' => $display,
            'amazon' => $amazon,
            'rakuten' => $rakuten,
            'human' => $human,
        ];
    }
}
