<?php

namespace App\Http\Controllers;

use App\Http\Requests\RelatedCocktailsRequest;
use App\Http\Resources\CocktailSummaryResource;
use App\Models\Cocktail;
use App\Services\Search\RelatedCocktails;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CocktailRelatedController extends Controller
{
    /**
     * 材料が似たカクテル(旧の「ランダムカクテル」のカクテルを起点にした形)を、ランダムの順で limit 件まで返す。
     *
     * 最初の 2 つの材料のどちらかと一致する材料を使うカクテルから選び、足りなければ材料を順に足す。下書きは返さず、
     * 下書き・無いカクテルを指定したら 404(docs/decisions/related-cocktails.md)。
     */
    public function __invoke(int $cocktail, RelatedCocktailsRequest $request, RelatedCocktails $related): AnonymousResourceCollection
    {
        $target = Cocktail::query()->published()->findOrFail($cocktail);

        return CocktailSummaryResource::collection($related->similarTo($target, $request->limit()));
    }
}
