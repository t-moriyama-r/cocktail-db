<?php

namespace App\Http\Resources;

use App\Models\Method;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 検索フォームの技法 1 つ(GET /api/search-form。#95)。
 *
 * @property Method $resource
 */
class SearchFormMethodResource extends JsonResource
{
    /**
     * @return array{id: int, name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
        ];
    }
}
