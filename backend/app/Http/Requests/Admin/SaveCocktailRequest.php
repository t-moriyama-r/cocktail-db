<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AdminFormInput;
use App\Models\DictionaryCategory;
use App\Rules\NotRootCategory;
use App\Services\AdminImageStore;
use App\Support\YoutubeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * カクテルの基本情報の新規・更新(POST /api/admin/cocktails・PUT /api/admin/cocktails/{id}。#291)の入力。
 *
 * 旧の EditCocktailValidate の規則に、長さの上限とベースの規則を足したもの。材料は #292 で足す。
 * 画像を上げるときは multipart で送る(PHP は PUT の multipart を読まないので、更新は POST に `_method=PUT` を付けて送る)。
 */
class SaveCocktailRequest extends FormRequest
{
    use AdminFormInput;

    /**
     * multipart(画像つき)では真偽値が文字列の "true"・"false" で届くので、boolean の規則が読める 1・0 に直す。
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanStrings(['is_major', 'is_draft']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ベース。どの深さのノードでもよいが、根(旧の種別)は選べない(#153)
            'base_category_id' => ['required', 'integer', 'exists:categories,id', app(NotRootCategory::class)],
            'name' => ['required', 'string', 'max:255'],
            'name_eng' => ['required', 'string', 'max:255'],
            // 技法・グラス(用語辞典の分類「グラス」の項目)・氷。選択肢は GET /api/admin/cocktail-form-options
            'method_id' => ['required', 'integer', 'exists:methods,id'],
            'glass_dictionary_id' => [
                'nullable', 'integer',
                Rule::exists('dictionaries', 'id')->where('dictionary_category_id', DictionaryCategory::GLASS_ID),
            ],
            'ice_id' => ['nullable', 'integer', 'exists:ices,id'],
            // 評価・甘さ(旧と同じ範囲)
            'rate' => ['nullable', 'integer', 'between:1,5'],
            'sweet_degree' => ['nullable', 'integer', 'between:-10,10'],
            'is_major' => ['required', 'boolean'],
            // 下書きとして保存するか(true)、公開するか(false)。保存のボタンで決める(docs/decisions/admin-screens.md の 1)
            'is_draft' => ['required', 'boolean'],
            // 説明・補足。保存のときに記法へ変換する(DescriptionMarkup。#287)。
            // 公開するときは説明が要る(旧も説明の無いカクテルは公開にならなかった。#291 の「旧との差分」)
            'description' => [Rule::requiredIf(fn (): bool => $this->isPublishing()), 'nullable', 'string'],
            'note' => ['nullable', 'string'],
            // YouTube の URL か動画 ID と、開始位置(時・分・秒。時の 0 は #284 の 8)
            'youtube' => ['nullable', 'string', 'max:255'],
            'youtube_start_hour' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_HOUR],
            'youtube_start_minute' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
            'youtube_start_second' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
            // 画像のリンクの文字と URL(#288)
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

    /**
     * 「公開する」で保存したか(is_draft に偽を送ったか)。is_draft が無い・読めないときは偽(is_draft の誤りだけを返す)。
     */
    private function isPublishing(): bool
    {
        $value = $this->input('is_draft');

        return in_array($value, [false, 0, '0'], true);
    }
}
