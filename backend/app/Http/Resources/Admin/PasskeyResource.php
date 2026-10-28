<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passkeys\Passkey;

/**
 * 管理画面のパスキーの一覧の 1 つ(#311)。公開鍵などの資格情報そのものは返さない。
 *
 * @property Passkey $resource
 */
class PasskeyResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, created_at: string|null, last_used_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            // 登録した日時。一度も使っていなければ last_used_at は null
            'created_at' => $this->resource->created_at?->toJSON(),
            'last_used_at' => $this->resource->last_used_at?->toJSON(),
        ];
    }
}
