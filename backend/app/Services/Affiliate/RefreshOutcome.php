<?php

namespace App\Services\Affiliate;

/**
 * 商品の ID で取り直した行 1 件・提供元 1 つの結果(#249)。
 *
 * - REFRESHED: 取り直した値と取った時刻を保存した
 * - CLEARED: ID で取れなかった(販売終了など)ので、その提供元の ID と値を消した。ID も手で入れた値も残らなくなった
 *   行は消し(rowDeleted)、次の --new が名前から探し直す
 * - FAILED: API の失敗で保存しなかった。次の回にまた選ばれる
 * - RETRY_LATER: 上限・一時的な失敗で保存しなかった。この回は、その提供元をここで終える
 */
final class RefreshOutcome
{
    public const AMAZON = 'amazon';

    public const RAKUTEN = 'rakuten';

    public const REFRESHED = 'refreshed';

    public const CLEARED = 'cleared';

    public const FAILED = 'failed';

    public const RETRY_LATER = 'retry_later';

    /**
     * @param  self::AMAZON|self::RAKUTEN  $provider
     * @param  self::REFRESHED|self::CLEARED|self::FAILED|self::RETRY_LATER  $status
     */
    public function __construct(
        public readonly string $provider,
        public readonly int $affiliateId,
        public readonly string $itemId,
        public readonly string $status,
        public readonly bool $rowDeleted = false,
        public readonly ?string $message = null,
    ) {}

    public function describe(): string
    {
        $provider = $this->provider === self::AMAZON ? 'Amazon' : '楽天';

        return "行 {$this->affiliateId}({$provider} {$this->itemId})";
    }
}
