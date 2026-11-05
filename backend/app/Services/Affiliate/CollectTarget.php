<?php

namespace App\Services\Affiliate;

/**
 * 名前で商品を探すノード・銘柄の 1 件(#248)。銘柄なら brandId があり、categoryId は銘柄の所属ノード。
 */
final class CollectTarget
{
    public function __construct(
        public readonly int $categoryId,
        public readonly ?int $brandId,
        public readonly string $name,
    ) {}

    public function describe(): string
    {
        return $this->brandId !== null
            ? "銘柄 {$this->brandId}({$this->name})"
            : "ノード {$this->categoryId}({$this->name})";
    }
}
