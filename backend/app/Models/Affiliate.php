<?php

namespace App\Models;

use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * アフィリエイトの商品。ノード 1 つ(brand_id が null)、または銘柄 1 つにつき 1 行。
 *
 * brand_key は DB が brand_id から作る生成列で、一意性の制約にだけ使う。書き込むと DB に拒まれるので
 * Fillable に入れていない。
 */
#[Fillable([
    'category_id', 'brand_id',
    'amazon_asin', 'amazon_title', 'amazon_price',
    'amazon_image_large_url', 'amazon_image_medium_url', 'amazon_image_small_url',
    'amazon_detail_url', 'amazon_search_url', 'amazon_fetched_at',
    'rakuten_item_code', 'rakuten_title', 'rakuten_price',
    'rakuten_image_url', 'rakuten_detail_url', 'rakuten_search_url', 'rakuten_fetched_at',
    'human_title', 'human_price', 'human_image_url',
    'human_amazon_detail_url', 'human_amazon_search_url',
    'last_viewed_at',
])]
class Affiliate extends Model
{
    /** @use HasFactory<AffiliateFactory> */
    use HasFactory;

    /**
     * 商品を出すノード。銘柄の行では、銘柄の所属ノードと同じ。
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 商品の銘柄。ノードの行では null。銘柄を消すと、その銘柄の行も消える(外部キーの CASCADE)。
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'brand_key' => 'integer',
            'amazon_price' => 'integer',
            'amazon_fetched_at' => 'datetime',
            'rakuten_price' => 'integer',
            'rakuten_fetched_at' => 'datetime',
            'human_price' => 'integer',
            'last_viewed_at' => 'datetime',
        ];
    }
}
