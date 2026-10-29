<?php

namespace App\Services\Affiliate;

use Carbon\CarbonInterface;

/**
 * Amazon・楽天から取った商品の値(商品名・価格・画像・URL)を、まだ出してよいか(docs/decisions/affiliate-freshness.md の 2)。
 *
 * どちらも、取った時刻から TTL_HOURS 時間のあいだだけ出す。ちょうど TTL_HOURS 時間たった時点で期限切れ。
 * 手で入れた値(human_*)は API から取った値ではないので、ここを通さず期限なしで出す。
 */
final class AffiliateFreshness
{
    /** Amazon のライセンス契約(利用要件 (n))の 24 時間と、楽天の価格のキャッシュの 24 時間に揃える */
    public const TTL_HOURS = 24;

    /**
     * 取った時刻が $fetchedAt の値を、$now に出してよいか。取った時刻が無ければ出さない。
     */
    public static function isFresh(?CarbonInterface $fetchedAt, CarbonInterface $now): bool
    {
        return $fetchedAt !== null && $now->lessThan($fetchedAt->copy()->addHours(self::TTL_HOURS));
    }
}
