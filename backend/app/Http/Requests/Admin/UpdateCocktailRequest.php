<?php

namespace App\Http\Requests\Admin;

/**
 * カクテルの更新(PUT /api/admin/cocktails/{id})の入力。規則は新規と同じ SaveCocktailRequest で、更新では材料
 * (ingredients)を送らなければ今の材料を残す(#720)。
 *
 * 新規と別のクラスにするのは、OpenAPI(Scramble)に新規と別のスキーマを出し、更新の ingredients を任意にするため(#721)。
 * Scramble はルートの無いところで規則を読むので、ルートのカクテルの有無ではなく、クラスで更新かを決める。
 */
class UpdateCocktailRequest extends SaveCocktailRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }
}
