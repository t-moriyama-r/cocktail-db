<?php

namespace App\Services;

use App\Enums\DictionaryDeleteBlocker;
use RuntimeException;

/**
 * AdminDictionaryWriter::delete で、用語を消せない理由があったときの例外。理由をすべて持つ。
 */
final class DictionaryNotDeletableException extends RuntimeException
{
    /**
     * @param  non-empty-list<DictionaryDeleteBlocker>  $blockers  消せない理由(DictionaryDeleteBlocker の定義の順)
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('用語を消せない: '.implode(', ', array_map(
            fn (DictionaryDeleteBlocker $blocker): string => $blocker->value,
            $blockers,
        )));
    }
}
