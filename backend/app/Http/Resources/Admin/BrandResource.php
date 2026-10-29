<?php

namespace App\Http\Resources\Admin;

use App\Models\Brand;
use App\Services\DescriptionMarkup;
use App\Support\YoutubeInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面の銘柄の編集で開く値(GET /api/admin/brands/{id} と、新規・更新の応答。#314)。
 *
 * 説明は、編集で開く形(記法に指す先の名前を添えたもの。DescriptionMarkup::withNotes)で返す。
 * YouTube の開始位置は、画面の欄に合わせて時・分・秒に分けて返す。画像は保存した値のまま返す
 * (public ディスクのファイル名か、外部の URL。AdminImageStore)。
 * 所属のパンくずと有名な銘柄かは、BrandPlacement で引いたものを銘柄の属性 placement に入れて渡す。
 *
 * @property Brand $resource
 */
class BrandResource extends JsonResource
{
    /**
     * @return array{id: int, category_id: int, category_path: list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}>, is_famous_brand: bool, name: string, name_eng: string, strength: float, description: string|null, youtube: string|null, youtube_start_hour: int|null, youtube_start_minute: int|null, youtube_start_second: int|null, youtube_created_at: string|null, is_major: bool, is_like: bool, image: string|null, image_txt: string|null, image_url: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource;
        /** @var array{path: list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}>, is_famous_brand: bool} $placement */
        $placement = $brand->getAttribute('placement');
        $start = YoutubeInput::splitSeconds($brand->youtube_start_seconds);

        return [
            'id' => $brand->id,
            'category_id' => $brand->category_id,
            // 所属ノードのパンくず(根を除き、浅い順)。ノードごとに、その有名な銘柄がこの銘柄か
            /** @var list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}> */
            'category_path' => $placement['path'],
            // どこかのノードの有名な銘柄になっているか(所属を変えられる範囲が限られる)
            /** @var bool */
            'is_famous_brand' => $placement['is_famous_brand'],
            'name' => $brand->name,
            'name_eng' => $brand->name_eng,
            // 度数(%)
            'strength' => (float) $brand->volume,
            // 編集で開く形(記法に指す先の名前を添えたもの)
            /** @var string|null */
            'description' => app(DescriptionMarkup::class)->withNotes($brand->description),
            // 動画 ID
            'youtube' => $brand->youtube,
            /** @var int|null */
            'youtube_start_hour' => $start['hour'],
            /** @var int|null */
            'youtube_start_minute' => $start['minute'],
            /** @var int|null */
            'youtube_start_second' => $start['second'],
            // 動画を入れた・変えた日時
            'youtube_created_at' => $brand->youtube_created_at,
            'is_major' => $brand->is_major,
            'is_like' => $brand->is_like,
            'image' => $brand->image,
            'image_txt' => $brand->image_txt,
            'image_url' => $brand->image_url,
            'updated_at' => $brand->updated_at?->toJSON(),
        ];
    }
}
