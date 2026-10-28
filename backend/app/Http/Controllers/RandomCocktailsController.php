<?php

namespace App\Http\Controllers;

use App\Http\Requests\RandomCocktailsRequest;
use App\Http\Resources\CocktailSummaryResource;
use App\Models\Brand;
use App\Services\Search\RelatedCocktails;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RandomCocktailsController extends Controller
{
    /**
     * ノード・銘柄・ベース・技法・グラスのどれか 1 つを起点にした別のカクテルを、ランダムの順で limit 件まで返す。
     *
     * 下書きは返さない。無いノード・銘柄・ベース・グラスを指定したら、当たるものが無いので空の配列(旧と同じ)。
     * 何が当たるかは docs/decisions/related-cocktails.md。
     */
    public function __invoke(RandomCocktailsRequest $request, RelatedCocktails $related): AnonymousResourceCollection
    {
        [$origin, $id] = $request->origin();
        $limit = $request->limit();

        $cocktails = match ($origin) {
            'category_id' => $related->usingNode($id, $limit),
            'brand_id' => ($brand = Brand::query()->find($id)) === null
                ? new Collection
                : $related->usingBrand($brand, $limit),
            'base_id' => $related->withSame('base_category_id', $id, $limit),
            'method_id' => $related->withSame('method_id', $id, $limit),
            'glass_id' => $related->withSame('glass_dictionary_id', $id, $limit),
        };

        return CocktailSummaryResource::collection($cocktails);
    }
}
