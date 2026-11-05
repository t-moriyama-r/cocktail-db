<?php

namespace App\Services\Affiliate;

/**
 * 商品を探したノード・銘柄 1 件の結果(#248)。
 *
 * - SAVED: 行を作った。見つかった提供元の ID が入る(どちらも null なら、見つからずに空の行を作った)
 * - ALREADY_EXISTS: 探しているあいだに行ができていたので、書き換えなかった
 * - RETRY_LATER: 上限・一時的な失敗で保存しなかった。この回はここで終える
 * - FAILED: API の失敗で保存しなかった。次の件へ進む
 */
final class CollectOutcome
{
    public const SAVED = 'saved';

    public const ALREADY_EXISTS = 'already_exists';

    public const RETRY_LATER = 'retry_later';

    public const FAILED = 'failed';

    /**
     * @param  self::SAVED|self::ALREADY_EXISTS|self::RETRY_LATER|self::FAILED  $status
     */
    public function __construct(
        public readonly CollectTarget $target,
        public readonly string $status,
        public readonly ?string $amazonAsin = null,
        public readonly ?string $rakutenItemCode = null,
        public readonly ?string $message = null,
    ) {}
}
