<?php

namespace App\Services\Affiliate;

use App\Enums\AffiliateNotFoundReason;
use Illuminate\Support\Carbon;

/**
 * 商品を探したノード・銘柄 1 件の結果(#248)。
 *
 * - SAVED: 行を作った。見つかった提供元の ID が入る(どちらも null なら、見つからずに空の行を作った)
 * - ALREADY_EXISTS: 探しているあいだに行ができていたので、書き換えなかった
 * - RETRY_LATER: 上限・一時的な失敗で保存しなかった。この回はここで終える
 * - FAILED: API の失敗で保存しなかった。次の件へ進む。retryAfter までは、この件を選ばない(#695。消されていたら null)
 * - RESEARCHED: 行のあるノード・銘柄で、ID の無い提供元を名前で探し直して保存した(#696)。見つかった提供元の ID と、
 *   見つからなかった提供元の理由が入る。探さなかった提供元は、ID も理由も null
 * - CHANGED: 探し直しているあいだに行が変わった(その提供元の ID が入った・行が消えた)ので、書き換えなかった(#696)
 */
final class CollectOutcome
{
    public const SAVED = 'saved';

    public const ALREADY_EXISTS = 'already_exists';

    public const RETRY_LATER = 'retry_later';

    public const FAILED = 'failed';

    public const RESEARCHED = 'researched';

    public const CHANGED = 'changed';

    /**
     * @param  self::SAVED|self::ALREADY_EXISTS|self::RETRY_LATER|self::FAILED|self::RESEARCHED|self::CHANGED  $status
     */
    public function __construct(
        public readonly CollectTarget $target,
        public readonly string $status,
        public readonly ?string $amazonAsin = null,
        public readonly ?string $rakutenItemCode = null,
        public readonly ?string $message = null,
        public readonly ?Carbon $retryAfter = null,
        public readonly ?AffiliateNotFoundReason $amazonReason = null,
        public readonly ?AffiliateNotFoundReason $rakutenReason = null,
    ) {}
}
