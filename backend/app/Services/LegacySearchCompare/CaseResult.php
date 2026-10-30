<?php

namespace App\Services\LegacySearchCompare;

/**
 * 1 組の条件を突き合わせた結果。
 */
final readonly class CaseResult
{
    /**
     * @param  int  $legacyCount  旧(そのまま再現したもの)で当たった件数
     * @param  int  $newCount  新で当たった件数
     * @param  array<string, int>  $explained  説明できた差分の名前 => 件数
     * @param  list<string>  $unexplained  説明できない差分(カクテルごとに 1 行)
     * @param  string|null  $skipped  流さなかった理由。流したなら null
     */
    public function __construct(
        public SearchCase $case,
        public int $legacyCount,
        public int $newCount,
        public array $explained,
        public array $unexplained,
        public ?string $skipped = null,
    ) {}

    public static function skipped(SearchCase $case, string $reason): self
    {
        return new self($case, 0, 0, [], [], $reason);
    }

    public function failed(): bool
    {
        return $this->unexplained !== [];
    }
}
