<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 名前の横断検索(GET /api/search。#172)で見つかったノード 1 つ。
 *
 * 旧はミドルにだけ親のカテゴリを付けていた(FromNameSearch\MiddleResource)。新しい木ではカテゴリとミドルが
 * どちらもノードになるので、すべてに親を付ける(根は返さないので、親はいつもある)。
 *
 * @property Category $resource
 */
class NameSearchNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $node = $this->resource->loadMissing('parent');

        return [
            'id' => $node->id,
            'name' => $node->name,
            'name_eng' => $node->name_eng,
            'parent' => NodeRefResource::make($node->parent),
        ];
    }
}
