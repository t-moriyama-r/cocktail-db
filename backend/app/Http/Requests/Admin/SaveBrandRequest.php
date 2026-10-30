<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AdminFormInput;
use App\Rules\NotRootCategory;
use App\Services\AdminImageStore;
use App\Support\YoutubeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 銘柄の新規・更新(POST /api/admin/brands・PUT /api/admin/brands/{id}。#314)の入力。
 *
 * 旧の EditBrandValidate の規則に、長さの上限などを足したもの。画像を上げるときは multipart で送る
 * (PHP は PUT の multipart を読まないので、更新は POST に `_method=PUT` を付けて送る)。
 */
class SaveBrandRequest extends FormRequest
{
    use AdminFormInput;

    /**
     * multipart(画像つき)では真偽値が文字列の "true"・"false" で届くので、boolean の規則が読める 1・0 に直す。
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanStrings(['is_major', 'is_like']);
    }

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
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
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
        return [$this->youtubeValidation()];
    }
}
