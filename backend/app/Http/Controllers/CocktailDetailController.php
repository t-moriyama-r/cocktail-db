<?php

namespace App\Http\Controllers;

use App\Http\Resources\CocktailDetailResource;
use App\Models\Cocktail;

class CocktailDetailController extends Controller
{
    /**
     * カクテルの詳細を返す。基本情報・技法(と、それを説明する用語の ID)・グラス・氷と、材料を並び順(position)で返す。
     * 材料ごとに、ノードの根からのパンくずと銘柄を返す。
     *
     * 下書き・無いカクテルは 404(旧は下書きも返し、無い ID は 500 だった。docs/decisions/cocktail-detail.md)。
     */
    public function __invoke(int $cocktail): CocktailDetailResource
    {
        return CocktailDetailResource::make(Cocktail::query()->published()->findOrFail($cocktail));
    }
}
