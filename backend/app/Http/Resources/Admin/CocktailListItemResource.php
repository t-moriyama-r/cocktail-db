<?php

namespace App\Http\Resources\Admin;

use App\Models\Cocktail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のカクテルの一覧の 1 行(GET /api/admin/cocktails。#289)。
 *
 * @property Cocktail $resource
 */
class CocktailListItemResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, name_eng: string|null, is_draft: bool, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'name_eng' => $this->resource->name_eng,
            'is_draft' => $this->resource->is_draft,
            'updated_at' => $this->resource->updated_at?->toJSON(),
        ];
    }
}
