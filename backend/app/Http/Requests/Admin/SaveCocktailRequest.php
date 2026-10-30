<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AdminFormInput;
use App\Models\CocktailAlias;
use App\Models\DictionaryCategory;
use App\Rules\NotRootCategory;
use App\Services\AdminImageStore;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * カクテルの基本情報の新規・更新(POST /api/admin/cocktails・PUT /api/admin/cocktails/{id}。#291)の入力。
 *
 * 旧の EditCocktailValidate の規則に、長さの上限とベースの規則を足したもの。材料は #292 で足す。
 * 別名(aliases。#293)は旧に無い項目で、送ったときだけ、送った並びに置き換える(下の aliases())。
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
            // 別名(#293)。名前の配列で、送った並びに置き換える。送らなければ今の別名を残す。
            // 全部消すときは空の配列(multipart では空の値の aliases。空文字は null で届く)を送る
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * 保存する別名の名前。送った並びのまま返す。aliases を送っていなければ null(今の別名を残す)。
     *
     * @return list<string>|null
     */
    public function aliases(): ?array
    {
        if (! $this->has('aliases')) {
            return null;
        }

        /** @var array<array-key, string>|null $aliases */
        $aliases = $this->input('aliases');

        return array_values($aliases ?? []);
    }

    /**
     * YouTube の URL が読めるかを、ほかの項目の誤りと一緒に返す。
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->youtubeValidation(), $this->aliasesValidation()];
    }

    /**
     * 別名の検索用の名前(NameNormalizer)が、ほかの別名やカクテル自身の名前と同じなら誤りにする(#293)。
     *
     * 同じカクテルに、検索用の名前が同じ別名は 2 つ置けない(cocktail_aliases の unique)。名前と同じ別名は、
     * 名前の横断検索で何も足さないので受けない。別名を送らずに名前だけを変えたときも、今の別名と比べる。
     *
     * @return callable(Validator): void
     */
    private function aliasesValidation(): callable
    {
        return function (Validator $validator): void {
            $errors = $validator->errors();
            if ($errors->has('name') || $errors->has('aliases') || $errors->has('aliases.*')) {
                return;
            }
            $nameSearch = NameNormalizer::normalize($this->string('name')->toString());
            $aliases = $this->aliases();

            if ($aliases === null) {
                $id = $this->route('cocktail');
                $sameAsName = $id !== null && CocktailAlias::query()
                    ->where('cocktail_id', $id)
                    ->where('name_search', $nameSearch)
                    ->exists();
                if ($sameAsName) {
                    $errors->add('name', '別名と同じ名前です');
                }

                return;
            }

            $seen = [];
            foreach ($aliases as $index => $alias) {
                $aliasSearch = NameNormalizer::normalize($alias);
                // DB の照合順序(utf8mb4_unicode_ci)は大文字小文字を区別しないので、unique に当たらないよう小文字にそろえて重複を見る
                $foldedSearch = mb_strtolower($aliasSearch, 'UTF-8');
                if ($aliasSearch === $nameSearch) {
                    $errors->add("aliases.{$index}", 'カクテルの名前と同じ別名です');
                } elseif (isset($seen[$foldedSearch])) {
                    $errors->add("aliases.{$index}", '同じ別名がほかにあります');
                }
                $seen[$foldedSearch] = true;
            }
        };
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
