<?php

namespace App\Services\Affiliate;

/**
 * 楽天市場の商品検索 API から取った 1 件の商品。旧(App\Classes\Rakuten)が持っていた項目と、取り直しに使う
 * 商品のコード・販売できるかを持つ。
 *
 * 応答に無い項目は null。price は itemPrice(円)。税込かどうか・送料を含むかは、楽天の taxFlag・postageFlag で
 * 変わるが、旧と同じく見ない(docs/rakuten-api.md の「確かめていないこと」)。
 */
final class RakutenItem
{
    public function __construct(
        public readonly string $itemCode,
        public readonly ?string $title,
        public readonly ?int $price,
        public readonly ?string $detailUrl,
        public readonly ?string $imageUrl,
        public readonly ?bool $available,
    ) {}
}
