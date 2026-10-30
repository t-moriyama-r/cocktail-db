<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 名前の横断検索(GET /api/search。#172)で見つかったノード 1 つ。
 *
 * 旧はミドルにだけ親のカテゴリを付けていた(FromNameSearch\MiddleResource)。新しい木ではカテゴリとミドルが
 * どちらもノードになるので、すべてに親を付ける(根は返さないので、親はいつもある)。
 * 結果の画面(#52)が、根を除く祖先を docs/decisions/public-detail.md の決まったこと 3 の形で出せるよう、
 * 根からのパンくず(path)も返す。
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
        $node = $this->resource->loadMissing([
            'parent',
            'ancestors' => fn (BelongsToMany $ancestors) => $ancestors->orderByPivot('depth', 'desc'),
        ]);

        return [
            'id' => $node->id,
            'name' => $node->name,
            'name_eng' => $node->name_eng,
            'parent' => NodeRefResource::make($node->parent),
            // 根が先頭で、このノードが末尾(ノードの詳細の path と同じ並び)
            'path' => NodeRefResource::collection($node->ancestors->concat([$node])),
        ];
    }
}
