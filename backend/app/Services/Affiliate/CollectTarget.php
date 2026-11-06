<?php

namespace App\Services\Affiliate;

/**
 * 名前で商品を探すノード・銘柄の 1 件(#248)。銘柄なら brandId があり、categoryId は銘柄の所属ノード。
 *
 * 行のあるノード・銘柄を探し直すとき(#696)は affiliateId に行の ID が入り、searchAmazon・searchRakuten が、
 * どちらの提供元を探し直すかを示す。行の無いノード・銘柄(affiliateId が null)は、認証情報のある提供元をどれも探す。
 */
final class CollectTarget
{
    public function __construct(
        public readonly int $categoryId,
        public readonly ?int $brandId,
        public readonly string $name,
        public readonly ?int $affiliateId = null,
        public readonly bool $searchAmazon = true,
        public readonly bool $searchRakuten = true,
    ) {}

    public function describe(): string
    {
        $target = $this->brandId !== null
            ? "銘柄 {$this->brandId}({$this->name})"
            : "ノード {$this->categoryId}({$this->name})";

        return $this->affiliateId !== null ? "{$target}・行 {$this->affiliateId}" : $target;
    }
}
