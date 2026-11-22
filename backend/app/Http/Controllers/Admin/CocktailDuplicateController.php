<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CocktailDuplicateResource;
use App\Services\CocktailDuplicates;
use Illuminate\Http\Request;

class CocktailDuplicateController extends Controller
{
    /**
     * カクテルの編集画面の重複チェック(#129。docs/decisions/admin-screens.md の決まったこと 3)。保存は止めない。
     *
     * - same_name: 英名が同じか、検索用の名前が同じカクテル(下書きも含む。exclude_id のカクテルは除く)。exclude_id の
     *   カクテルの保存してある材料に近い順(match の same_amount → same_ingredients → contains_all → other)、同じなら ID の順。
     *   旧 DetailEditController@duplicate_get の置き換え
     * - same_recipe: exclude_id のカクテルと材料の組(recipe_signature。#82)がまったく同じカクテル(下書きも含む)を ID の順。
     *   旧には無い。exclude_id が無い・材料の無いカクテルなら空
     *
     * 名前・英名は、空なら比べない(どちらか 1 つは要る)。材料は送った入力ではなく、exclude_id の保存してある材料で比べる(旧と同じ)。
     *
     * @return array{same_name: list<CocktailDuplicateResource>, same_recipe: list<CocktailDuplicateResource>}
     */
    public function __invoke(Request $request, CocktailDuplicates $duplicates): array
    {
        $request->validate([
            // 名前。英名と、どちらか 1 つは要る
            'name' => ['required_without:name_eng', 'nullable', 'string', 'max:255'],
            // 英名。名前と、どちらか 1 つは要る
            'name_eng' => ['required_without:name', 'nullable', 'string', 'max:255'],
            // 編集しているカクテルの ID。新規なら送らない
            'exclude_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $found = $duplicates->find(
            $request->filled('name') ? $request->string('name')->toString() : null,
            $request->filled('name_eng') ? $request->string('name_eng')->toString() : null,
            $request->filled('exclude_id') ? $request->integer('exclude_id') : null,
        );
        $result = ['same_name' => [], 'same_recipe' => []];
        foreach (['same_name', 'same_recipe'] as $key) {
            foreach ($found[$key] as $item) {
                $result[$key][] = new CocktailDuplicateResource($item['cocktail'], $item['match']);
            }
        }

        return $result;
    }
}
