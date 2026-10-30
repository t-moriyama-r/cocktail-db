<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCocktailRequest;
use App\Http\Resources\Admin\CocktailResource;
use App\Models\Cocktail;
use App\Services\AdminCocktailWriter;
use Illuminate\Http\JsonResponse;

/**
 * 管理画面のカクテルの基本情報(#291。画面は #127)。旧の DetailEditController@cocktail の置き換え(材料は #292)。
 */
class CocktailController extends Controller
{
    /**
     * 編集で開くカクテル。下書きも返す。
     */
    public function show(int $cocktail): CocktailResource
    {
        return CocktailResource::make(Cocktail::query()->findOrFail($cocktail));
    }

    /**
     * カクテルを作る。下書きか公開かは is_draft で決める(docs/decisions/admin-screens.md の 1)。
     */
    public function store(SaveCocktailRequest $request, AdminCocktailWriter $writer): JsonResponse
    {
        return CocktailResource::make($writer->save(null, $request))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * カクテルの基本情報を書き換える。材料と、材料から作る値(度数・材料の数)は変えない。
     */
    public function update(SaveCocktailRequest $request, AdminCocktailWriter $writer, int $cocktail): CocktailResource
    {
        return CocktailResource::make($writer->save(Cocktail::query()->findOrFail($cocktail), $request));
    }
}
