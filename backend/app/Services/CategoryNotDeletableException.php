<?php

namespace App\Services;

use App\Enums\CategoryDeleteBlocker;
use App\Enums\CategoryReferenceBlocker;
use RuntimeException;

/**
 * CategoryTree::deleteLeaf・AdminCategoryWriter::delete で、ノードを消せない理由があったときの例外。理由をすべて持つ。
 */
final class CategoryNotDeletableException extends RuntimeException
{
    /**
     * @param  list<CategoryDeleteBlocker>  $blockers  木の形・外部キーで消せない理由(CategoryDeleteBlocker の定義の順)
     * @param  list<array{blocker: CategoryReferenceBlocker, referrers: non-empty-list<array{id: int, name: string}>}>  $references
     *                                                                                                                               説明文から参照されていて消せない理由(CategoryReferenceBlocker の定義の順。#629)と、
     *                                                                                                                               参照している行(ID の順)。CategoryTree::deleteLeaf は調べないので空
     */
    public function __construct(public readonly array $blockers, public readonly array $references = [])
    {
        parent::__construct('カテゴリを消せない: '.implode(', ', [
            ...array_map(fn (CategoryDeleteBlocker $blocker): string => $blocker->value, $blockers),
            ...array_map(fn (array $reference): string => $reference['blocker']->value, $references),
        ]));
    }

    /**
     * API で返す消せない理由(DELETE の 409 の reasons と、編集で開く値の delete_blockers)。木の形・外部キーの理由が先で、
     * 説明文からの参照の理由には、参照している行を referrers で添える。
     *
     * @return list<array{code: value-of<CategoryDeleteBlocker>|value-of<CategoryReferenceBlocker>, message: string, referrers?: non-empty-list<array{id: int, name: string}>}>
     */
    public function reasons(): array
    {
        return [
            ...array_map(fn (CategoryDeleteBlocker $blocker): array => ['code' => $blocker->value, 'message' => $blocker->message()], $this->blockers),
            ...array_map(fn (array $reference): array => [
                'code' => $reference['blocker']->value,
                'message' => $reference['blocker']->message(),
                'referrers' => $reference['referrers'],
            ], $this->references),
        ];
    }
}
