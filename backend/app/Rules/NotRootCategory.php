<?php

namespace App\Rules;

use App\Models\Category;
use App\Services\CategoryTree;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 値(categories の ID)が根のノードでないことを確かめる(docs/decisions/category-root-role.md の 4。#153)。
 *
 * 根は旧の種別(洋酒・ソフトドリンクなど)にあたり、旧のレシピとベースも種別を指すことはできなかった。
 * 根を指せると、「洋酒」のような大きすぎる材料が入る。
 *
 * ID の形やノードがあるかは見ない(`integer`・`exists:categories,id` の役目)。無い ID では何も言わない。
 */
class NotRootCategory implements ValidationRule
{
    public function __construct(private readonly CategoryTree $tree) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return;
        }

        $node = Category::query()->find((int) $value);
        if ($node !== null && $this->tree->isRoot($node)) {
            $fail(':attribute には、種別(根のノード)ではなく、その下のノードを選んでください。');
        }
    }
}
