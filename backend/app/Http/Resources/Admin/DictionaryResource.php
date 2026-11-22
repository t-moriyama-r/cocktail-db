<?php

namespace App\Http\Resources\Admin;

use App\Models\Dictionary;
use App\Services\DescriptionMarkup;
use App\Support\YoutubeInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面の用語の編集で開く値(GET /api/admin/dictionaries/{id} と、新規・更新の応答。#315)。
 *
 * 説明は、編集で開く形(記法に指す先の名前を添えたもの。DescriptionMarkup::withNotes)で返す。
 * YouTube の開始位置は、画面の欄に合わせて時・分・秒に分けて返す。画像は保存した値のまま返す
 * (public ディスクのファイル名か、外部の URL。AdminImageStore)。銘柄(BrandResource)と同じ形にそろえる。
 *
 * @property Dictionary $resource
 */
class DictionaryResource extends JsonResource
{
    /**
     * @return array{id: int, dictionary_category_id: int, name: string, name_eng: string, description: string|null, youtube: string|null, youtube_start_hour: int|null, youtube_start_minute: int|null, youtube_start_second: int|null, image: string|null, image_txt: string|null, image_url: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $dictionary = $this->resource;
        $start = YoutubeInput::splitSeconds($dictionary->youtube_start_seconds);

        return [
            'id' => $dictionary->id,
            // 分類(製法・グラスなど)
            'dictionary_category_id' => $dictionary->dictionary_category_id,
            'name' => $dictionary->name,
            'name_eng' => $dictionary->name_eng,
            // 編集で開く形(記法に指す先の名前を添えたもの)
            /** @var string|null */
            'description' => app(DescriptionMarkup::class)->withNotes($dictionary->description),
            // 動画 ID
            'youtube' => $dictionary->youtube,
            /** @var int|null */
            'youtube_start_hour' => $start['hour'],
            /** @var int|null */
            'youtube_start_minute' => $start['minute'],
            /** @var int|null */
            'youtube_start_second' => $start['second'],
            'image' => $dictionary->image,
            'image_txt' => $dictionary->image_txt,
            'image_url' => $dictionary->image_url,
            'updated_at' => $dictionary->updated_at?->toJSON(),
        ];
    }
}
