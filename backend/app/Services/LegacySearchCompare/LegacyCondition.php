<?php

namespace App\Services\LegacySearchCompare;

/**
 * 旧の条件検索の条件(旧の SearchCocktail が受けた値)。SearchCase の選び方を旧 DB で引いて、ID に直したもの。
 */
final readonly class LegacyCondition
{
    /** 旧の「ミドルを問わない」特別な値(docs/search.md の「材料の一致」) */
    public const ANY_MIDDLE = 999999;

    /**
     * @param  list<int>  $categories  Category[]
     * @param  list<int>  $middles  Middle[]
     * @param  list<int>  $methods  Method[]
     */
    public function __construct(
        public string $mode,
        public array $categories,
        public array $middles,
        public array $methods,
        public bool $easy,
    ) {}

    /**
     * 旧の OR 検索で、ミドルを問わずカテゴリだけで一致させる特別な値か(`Middle[0]` が 999999)。
     */
    public function anyMiddle(): bool
    {
        return ($this->middles[0] ?? null) === self::ANY_MIDDLE;
    }
}
