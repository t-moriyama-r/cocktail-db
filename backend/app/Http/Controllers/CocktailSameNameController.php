<?php

namespace App\Http\Controllers;

use App\Http\Resources\CocktailSummaryResource;
use App\Models\Cocktail;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CocktailSameNameController extends Controller
{
    /**
     * 同じ名前の別のカクテルを、ID の順で返す。
     *
     * 「同じ名前」は英名(name_eng)が同じもの(旧 CocktailFactory::sameName)。下書きは返さず、下書き・無いカクテルを
     * 指定したら 404。英名の無いカクテルは比べる名前が無いので 0 件(docs/decisions/cocktail-detail.md)。
     */
    public function __invoke(int $cocktail): AnonymousResourceCollection
    {
        $target = Cocktail::query()->published()->findOrFail($cocktail);

        $sameName = $target->name_eng === null
            ? collect()
            : Cocktail::query()
                ->published()
                ->where('name_eng', $target->name_eng)
                ->whereKeyNot($target->id)
                ->orderBy('id')
                ->with(['baseCategory', 'method'])
                ->get();

        return CocktailSummaryResource::collection($sameName);
    }
}
