<?php

namespace App\Http\Resources\Admin;

use App\Support\SettingEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面の設定の 1 項目(#132)。秘密の項目は値を返さず、末尾 4 文字だけを返す(docs/decisions/settings.md)。
 *
 * @property SettingEntry $resource
 */
class SettingResource extends JsonResource
{
    /** 末尾 4 文字を見せるのは、この文字数以上の値だけ。短い値で 4 文字を見せると、値のほとんどが分かってしまうため */
    public const LAST_FOUR_MIN_LENGTH = 8;

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     secret: bool,
     *     is_set: bool,
     *     value: string|null,
     *     last_four: string|null,
     *     source: 'saved'|'env'|null,
     *     updated_at: string|null,
     *     updated_by: array{id: int, name: string}|null,
     * }
     */
    public function toArray(Request $request): array
    {
        $definition = $this->resource->definition;
        $value = $this->resource->value->value;
        $row = $this->resource->row;
        $updatedBy = $row?->updatedBy;

        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'description' => $definition->description,
            'secret' => $definition->secret,
            // 画面で保存した値か .env の値があるか(秘密の項目の「設定済み」)
            'is_set' => $value !== null,
            // 秘密の項目は、どこから来た値でも返さない
            'value' => $definition->secret ? null : $value,
            'last_four' => $definition->secret ? self::lastFour($value) : null,
            // いま効いている値の出どころ。saved: 画面で保存した値、env: .env の値、null: どちらにも無い
            'source' => $this->resource->value->source,
            // 最後に画面で変えた日時と人(.env に戻したときも残る)。画面で一度も変えていなければ null
            'updated_at' => $row?->updated_at?->toJSON(),
            'updated_by' => $updatedBy === null ? null : ['id' => $updatedBy->id, 'name' => $updatedBy->name],
        ];
    }

    private static function lastFour(?string $value): ?string
    {
        if ($value === null || mb_strlen($value) < self::LAST_FOUR_MIN_LENGTH) {
            return null;
        }

        return mb_substr($value, -4);
    }
}
