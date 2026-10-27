<?php

namespace App\Services;

use App\Enums\CategoryDeleteBlocker;
use RuntimeException;

/**
 * CategoryTree::deleteLeaf で、ノードを消せない理由があったときの例外。理由をすべて持つ。
 */
final class CategoryNotDeletableException extends RuntimeException
{
    /**
     * @param  non-empty-list<CategoryDeleteBlocker>  $blockers  消せない理由(CategoryDeleteBlocker の定義の順)
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('カテゴリを消せない: '.implode(', ', array_map(
            fn (CategoryDeleteBlocker $blocker): string => $blocker->value,
            $blockers,
        )));
    }
}
