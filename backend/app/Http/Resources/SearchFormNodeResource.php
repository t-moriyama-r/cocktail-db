<?php

namespace App\Http\Resources;

use App\Services\CategoryTreeNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 検索フォームの木のノード 1 つ(GET /api/search-form。#95)。
 *
 * @property CategoryTreeNode $resource
 */
class SearchFormNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->category->id,
            'name' => $this->resource->category->name,
            // 旧の画面の「メジャーでない項目の表示/非表示」に使う
            'is_major' => $this->resource->category->is_major,
            // ノードの根の役割。あるもの検索で「その他」(other)を持っていなくてよいことを画面で示すのに使う
            'root_role' => $this->resource->rootRole,
            'children' => self::collection($this->resource->children),
        ];
    }
}
