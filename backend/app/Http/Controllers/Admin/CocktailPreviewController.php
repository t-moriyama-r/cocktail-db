<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CocktailDetailResource;
use App\Models\Cocktail;

class CocktailPreviewController extends Controller
{
    /**
     * 公開の詳細(GET /api/cocktails/{id})と同じ形で、下書きも含めてカクテルの詳細を返す。
     *
     * 公開の API はセッションを使わないので、ログインしているかで返すものを変えられない。管理者が下書きを
     * 公開の詳細と同じ形で確かめられるよう、管理 API に置く。
     */
    public function __invoke(int $cocktail): CocktailDetailResource
    {
        return CocktailDetailResource::make(Cocktail::query()->findOrFail($cocktail));
    }
}
