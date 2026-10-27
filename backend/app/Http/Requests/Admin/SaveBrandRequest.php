<?php

namespace App\Http\Requests\Admin;

use App\Rules\NotRootCategory;
use App\Services\AdminImageStore;
use App\Support\YoutubeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * 銘柄の新規・更新(POST /api/admin/brands・PUT /api/admin/brands/{id}。#314)の入力。
 *
 * 旧の EditBrandValidate の規則に、長さの上限などを足したもの。画像を上げるときは multipart で送る
 * (PHP は PUT の multipart を読まないので、更新は POST に `_method=PUT` を付けて送る)。
 */
class SaveBrandRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 所属するノード。どの深さでもよいが、根(旧の種別)は選べない
            'category_id' => ['required', 'integer', 'exists:categories,id', app(NotRootCategory::class)],
            'name' => ['required', 'string', 'max:255'],
            'name_eng' => ['required', 'string', 'max:255'],
            // 度数(%)。列(decimal(4,1))に入らない値を 500 にしないよう、0〜100 に絞る
            'strength' => ['required', 'numeric', 'between:0,100'],
            // 説明。保存のときに記法へ変換する(DescriptionMarkup。#287)
            'description' => ['required', 'string'],
            // YouTube の URL か動画 ID
            'youtube' => ['nullable', 'string', 'max:255'],
            'youtube_start_hour' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_HOUR],
            'youtube_start_minute' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
            'youtube_start_second' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
            'is_major' => ['required', 'boolean'],
            'is_like' => ['required', 'boolean'],
            // 画像のリンクの文字と URL
            'image_txt' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string'],
            ...AdminImageStore::rules(),
        ];
    }

    /**
     * YouTube の URL が読めるかを、ほかの項目の誤りと一緒に返す。
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('youtube')) {
                    return;
                }
                try {
                    YoutubeInput::videoId($this->youtubeInput());
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('youtube', $e->getMessage());
                }
            },
        ];
    }

    /**
     * 入れた YouTube の URL か動画 ID。空なら null。
     */
    public function youtubeInput(): ?string
    {
        return $this->filled('youtube') ? $this->string('youtube')->toString() : null;
    }

    /**
     * 整数の項目の値。空なら null。
     */
    public function nullableInt(string $key): ?int
    {
        return $this->filled($key) ? $this->integer($key) : null;
    }

    /**
     * 文字列の項目の値。空なら null。
     */
    public function nullableString(string $key): ?string
    {
        return $this->filled($key) ? $this->string($key)->toString() : null;
    }
}
