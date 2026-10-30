<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCocktailRequest;
use App\Http\Resources\Admin\CocktailResource;
use App\Models\Cocktail;
use App\Services\AdminCocktailWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * 管理画面のカクテルの基本情報(#291。画面は #127)と削除(#130)。旧の DetailEditController@cocktail・delete_cocktail の置き換え(材料は #292)。
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
     * 別名がほかのカクテルの名前・別名と同じなら、保存したうえで warnings に入れて返す(#293)。
     */
    public function store(SaveCocktailRequest $request, AdminCocktailWriter $writer): JsonResponse
    {
        $saved = $writer->save(null, $request);

        return CocktailResource::make($saved)
            ->additional(['warnings' => $writer->aliasWarnings($saved)])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * カクテルの基本情報と別名を書き換える。材料と度数は変えず、材料の数・材料の組のハッシュは今の材料から作り直す(#130)。
     * 別名がほかのカクテルの名前・別名と同じなら、保存したうえで warnings に入れて返す(#293)。
     */
    public function update(SaveCocktailRequest $request, AdminCocktailWriter $writer, int $cocktail): CocktailResource
    {
        $saved = $writer->save(Cocktail::query()->findOrFail($cocktail), $request);

        return CocktailResource::make($saved)->additional(['warnings' => $writer->aliasWarnings($saved)]);
    }

    /**
     * カクテルを消す。材料・別名も一緒に消える(#130)。画像のファイルも消す(ほかの行が指すファイルは残す。#578)。
     */
    public function destroy(AdminCocktailWriter $writer, int $cocktail): Response
    {
        $writer->delete(Cocktail::query()->findOrFail($cocktail));

        return response()->noContent();
    }
}
