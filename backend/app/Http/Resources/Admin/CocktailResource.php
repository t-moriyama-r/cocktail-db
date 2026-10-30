<?php

namespace App\Http\Resources\Admin;

use App\Models\Cocktail;
use App\Services\DescriptionMarkup;
use App\Support\YoutubeInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のカクテルの編集で開く基本情報と材料(GET /api/admin/cocktails/{id} と、新規・更新の応答。#291・#292)。
 *
 * 説明・補足は、編集で開く形(記法に指す先の名前を添えたもの。DescriptionMarkup::withNotes)で返す。
 * YouTube の開始位置は、画面の欄に合わせて時・分・秒に分けて返す。画像は保存した値のまま返す
 * (public ディスクのファイル名か、外部の URL。AdminImageStore)。材料は、保存のときに送る形のまま、レシピの順で返す。
 *
 * @property Cocktail $resource
 */
class CocktailResource extends JsonResource
{
    /**
     * @return array{id: int, base_category_id: int, name: string, name_eng: string|null, method_id: int, glass_dictionary_id: int|null, ice_id: int|null, strength: float, rate: int|null, sweet_degree: int|null, is_major: bool, is_draft: bool, description: string|null, note: string|null, youtube: string|null, youtube_start_hour: int|null, youtube_start_minute: int|null, youtube_start_second: int|null, youtube_created_at: string|null, image: string|null, image_txt: string|null, image_url: string|null, ingredients: AnonymousResourceCollection, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $cocktail = $this->resource;
        $markup = app(DescriptionMarkup::class);
        $start = YoutubeInput::splitSeconds($cocktail->youtube_start_seconds);

        return [
            'id' => $cocktail->id,
            'base_category_id' => $cocktail->base_category_id,
            'name' => $cocktail->name,
            // 移した旧のカクテルには英名の無いものがある(列は null 可)。保存では必須
            /** @var string|null */
            'name_eng' => $cocktail->name_eng,
            'method_id' => $cocktail->method_id,
            /** @var int|null */
            'glass_dictionary_id' => $cocktail->glass_dictionary_id,
            /** @var int|null */
            'ice_id' => $cocktail->ice_id,
            // 度数(%)。材料から計算した値で、入力しない
            'strength' => $cocktail->strength(),
            /** @var int|null */
            'rate' => $cocktail->rate,
            /** @var int|null */
            'sweet_degree' => $cocktail->sweet_degree,
            'is_major' => $cocktail->is_major,
            'is_draft' => $cocktail->is_draft,
            // 編集で開く形(記法に指す先の名前を添えたもの)
            /** @var string|null */
            'description' => $markup->withNotes($cocktail->description),
            /** @var string|null */
            'note' => $markup->withNotes($cocktail->note),
            // 動画 ID
            /** @var string|null */
            'youtube' => $cocktail->youtube,
            /** @var int|null */
            'youtube_start_hour' => $start['hour'],
            /** @var int|null */
            'youtube_start_minute' => $start['minute'],
            /** @var int|null */
            'youtube_start_second' => $start['second'],
            // 動画を入れた・変えた日時
            'youtube_created_at' => $cocktail->youtube_created_at,
            /** @var string|null */
            'image' => $cocktail->image,
            /** @var string|null */
            'image_txt' => $cocktail->image_txt,
            /** @var string|null */
            'image_url' => $cocktail->image_url,
            // 材料。レシピの順(position の昇順)で、保存のときに送る形(#292)
            'ingredients' => CocktailIngredientResource::collection($cocktail->ingredients),
            'updated_at' => $cocktail->updated_at?->toJSON(),
        ];
    }
}
