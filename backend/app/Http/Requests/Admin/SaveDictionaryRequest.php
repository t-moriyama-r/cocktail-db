<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AdminFormInput;
use App\Services\AdminImageStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 用語の新規・更新(POST /api/admin/dictionaries・PUT /api/admin/dictionaries/{id}。#315)の入力。
 *
 * 旧は用語の保存を止めていた(旧 routes/web.php の「面倒なのでサポート切り」)ので、旧の規則は無い。列に合わせた
 * 必須と長さの上限に、銘柄(#314)・カクテル(#291)と同じ YouTube・画像の規則を足したもの。
 * 画像を上げるときは multipart で送る(PHP は PUT の multipart を読まないので、更新は POST に `_method=PUT` を付けて送る)。
 */
class SaveDictionaryRequest extends FormRequest
{
    use AdminFormInput;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 分類(製法・グラスなど)
            'dictionary_category_id' => ['required', 'integer', 'exists:dictionary_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'name_eng' => ['required', 'string', 'max:255'],
            // 説明。保存のときに記法へ変換する(DescriptionMarkup。#287)
            'description' => ['required', 'string'],
            ...$this->youtubeRules(),
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
