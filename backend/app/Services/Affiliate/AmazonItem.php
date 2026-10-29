<?php

namespace App\Services\Affiliate;

/**
 * Creators API から取った 1 件の商品。旧(App\Classes\Amazon)が持っていた項目と同じものを持つ。
 *
 * 応答に無い項目は null。price は円の金額、displayPrice は Amazon が返した表示用の文字列。
 */
final class AmazonItem
{
    public function __construct(
        public readonly string $asin,
        public readonly ?string $title,
        public readonly ?string $detailPageUrl,
        public readonly ?string $imageUrlSmall,
        public readonly ?string $imageUrlMedium,
        public readonly ?string $imageUrlLarge,
        public readonly ?int $price,
        public readonly ?string $displayPrice,
    ) {}
}
