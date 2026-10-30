<?php

namespace App\Http\Resources\Admin;

use App\Models\Dictionary;
use App\Models\Ice;
use App\Models\Method;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のフォームの選択肢の 1 つ(技法・グラス・氷。GET /api/admin/cocktail-form-options。#291)。
 *
 * @property Method|Dictionary|Ice $resource
 */
class FormOptionResource extends JsonResource
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
