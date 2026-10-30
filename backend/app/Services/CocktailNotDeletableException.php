<?php

namespace App\Services;

use App\Enums\CocktailDeleteBlocker;
use RuntimeException;

/**
 * AdminCocktailWriter::delete で、カクテルを消せない理由があったときの例外。理由と、理由ごとの参照している行をすべて持つ。
 */
final class CocktailNotDeletableException extends RuntimeException
{
    /**
     * @param  non-empty-list<array{blocker: CocktailDeleteBlocker, referrers: non-empty-list<array{id: int, name: string}>}>  $reasons
     *                                                                                                                                   消せない理由(CocktailDeleteBlocker の定義の順)と、参照している行(ID の順)
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('カクテルを消せない: '.implode(', ', array_map(
            fn (array $reason): string => $reason['blocker']->value,
            $reasons,
        )));
    }
}
