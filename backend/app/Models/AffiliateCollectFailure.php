<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 名前で商品を探して失敗したノード・銘柄(#695)。ノード 1 つ(brand_id が null)、または銘柄 1 つにつき 1 行。
 * affiliates:collect --new は、retry_after までは、失敗したときと同じ名前のノード・銘柄を選ばない
 * (docs/decisions/affiliate-freshness.md の 7。中身は AffiliateCollector)。
 *
 * brand_key は DB が brand_id から作る生成列で、一意性の制約にだけ使う(Affiliate と同じ)。
 *
 * @property int $category_id
 * @property int|null $brand_id
 * @property string $name
 * @property int $failure_count
 * @property string|null $last_message
 * @property Carbon $retry_after
 */
#[Fillable(['category_id', 'brand_id', 'name', 'failure_count', 'last_message', 'retry_after'])]
class AffiliateCollectFailure extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'failure_count' => 'integer',
            'retry_after' => 'datetime',
        ];
    }
}
