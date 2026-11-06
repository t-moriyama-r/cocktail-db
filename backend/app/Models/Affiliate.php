<?php

namespace App\Models;

use App\Enums\AffiliateNotFoundReason;
use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * アフィリエイトの商品。ノード 1 つ(brand_id が null)、または銘柄 1 つにつき 1 行。
 *
 * brand_key は DB が brand_id から作る生成列で、一意性の制約にだけ使う。書き込むと DB に拒まれるので
 * Fillable に入れていない。
 *
 * @property Carbon|null $amazon_fetched_at
 * @property Carbon|null $amazon_searched_at
 * @property AffiliateNotFoundReason|null $amazon_not_found_reason
 * @property Carbon|null $rakuten_fetched_at
 * @property Carbon|null $rakuten_searched_at
 * @property AffiliateNotFoundReason|null $rakuten_not_found_reason
 * @property Carbon|null $last_viewed_at
 * @property-read Category $category 外部キー(RESTRICT)で、いつもある
 */
#[Fillable([
    'category_id', 'brand_id',
    'amazon_asin', 'amazon_title', 'amazon_price',
    'amazon_image_large_url', 'amazon_image_medium_url', 'amazon_image_small_url',
    'amazon_detail_url', 'amazon_search_url', 'amazon_fetched_at',
    'amazon_searched_at', 'amazon_not_found_reason',
    'rakuten_item_code', 'rakuten_title', 'rakuten_price',
    'rakuten_image_url', 'rakuten_detail_url', 'rakuten_search_url', 'rakuten_fetched_at',
    'rakuten_searched_at', 'rakuten_not_found_reason',
    'human_title', 'human_price', 'human_image_url',
    'human_amazon_detail_url', 'human_amazon_search_url',
    'last_viewed_at',
])]
class Affiliate extends Model
{
    /** @use HasFactory<AffiliateFactory> */
    use HasFactory;

    /** Amazon の商品の ID と、API から取った値の列(取れなかったときに、まとめて消す) */
    public const AMAZON_COLUMNS = [
        'amazon_asin', 'amazon_title', 'amazon_price',
        'amazon_image_large_url', 'amazon_image_medium_url', 'amazon_image_small_url',
        'amazon_detail_url', 'amazon_search_url', 'amazon_fetched_at',
    ];

    /** 楽天の商品の ID と、API から取った値の列(同じく) */
    public const RAKUTEN_COLUMNS = [
        'rakuten_item_code', 'rakuten_title', 'rakuten_price',
        'rakuten_image_url', 'rakuten_detail_url', 'rakuten_search_url', 'rakuten_fetched_at',
    ];

    /** 手で入れた値の列。収集(affiliates:collect)は書き換えない */
    public const HUMAN_COLUMNS = [
        'human_title', 'human_price', 'human_image_url', 'human_amazon_detail_url', 'human_amazon_search_url',
    ];

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
     * 商品の欄が見られた時刻(last_viewed_at)を今の時刻にする(#250)。取り直し(#249)が、見られた新しい順に使う。
     *
     * 書き込みを減らすため、前に見られてから 1 日たっていなければ書かない。同時に呼ばれても 1 回だけ書くよう、
     * 条件は UPDATE の WHERE に入れる。見られただけで行の中身は変わらないので、updated_at は変えない。
     */
    public function recordViewed(): void
    {
        $now = now();

        static::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull('last_viewed_at')
                ->orWhere('last_viewed_at', '<=', $now->copy()->subDay()))
            ->toBase()
            ->update(['last_viewed_at' => $now]);
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
            'amazon_searched_at' => 'datetime',
            'amazon_not_found_reason' => AffiliateNotFoundReason::class,
            'rakuten_price' => 'integer',
            'rakuten_fetched_at' => 'datetime',
            'rakuten_searched_at' => 'datetime',
            'rakuten_not_found_reason' => AffiliateNotFoundReason::class,
            'human_price' => 'integer',
            'last_viewed_at' => 'datetime',
        ];
    }
}
