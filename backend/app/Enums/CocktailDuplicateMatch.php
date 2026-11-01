<?php

namespace App\Enums;

/**
 * 重複チェック(#129)で並べるカクテルの、編集しているカクテルとの材料の近さ。旧 DetailEditController@duplicate_get の 4 つの並び。
 *
 * 材料は旧のカテゴリ(根のすぐ下のノード)で比べる。ミドル・銘柄・任意かは比べない(旧と同じ)。
 * 並べる順は、定義の順(近い順)。
 */
enum CocktailDuplicateMatch: string
{
    /** 材料の数が同じで、どの材料も同じカテゴリ・同じ量のものがある */
    case SameAmount = 'same_amount';

    /** 材料の数が同じで、編集しているカクテルのカテゴリをすべて含むが、量の違う材料がある */
    case SameIngredients = 'same_ingredients';

    /** 編集しているカクテルのカテゴリをすべて含むが、材料の数が違う */
    case ContainsAll = 'contains_all';

    /** 編集しているカクテルのカテゴリのどれかを含まない */
    case Other = 'other';
}
