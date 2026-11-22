<?php

namespace App\Http\Resources\Admin;

use App\Services\CategoryTreeNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のカテゴリ木のノード 1 つ(GET /api/admin/categories。#121。画面は #123)。子を入れ子で持つ。
 *
 * @property CategoryTreeNode $resource
 */
class CategoryTreeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $node = $this->resource->category;

        return [
            'id' => $node->id,
            'name' => $node->name,
            'name_eng' => $node->name_eng,
            // 根の役割。根だけが値を持ち、根でないノードと役割の無い根では null
            'role' => $node->role,
            // ノードの根の役割。子を足すときに、有名な銘柄が必須か(null なら必須)を画面で決めるのに使う
            'root_role' => $this->resource->rootRole,
            'is_major' => $node->is_major,
            'children' => self::collection($this->resource->children),
        ];
    }
}
