<?php

namespace App\Http\Resources\Admin;

use App\Models\SkipWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のスキップ語の 1 語(#316)。
 *
 * @property SkipWord $resource
 */
class SkipWordResource extends JsonResource
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
