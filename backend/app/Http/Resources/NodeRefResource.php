<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ほかのページから指すカテゴリ木のノード 1 つ(ノード詳細へのリンクと、名前・英名の表示に使う)。
 *
 * @property Category $resource
 */
class NodeRefResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, name_eng: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'name_eng' => $this->resource->name_eng,
        ];
    }
}
