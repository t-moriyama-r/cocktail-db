<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AdminFormInput;
use App\Models\Brand;
use App\Models\CocktailIngredient;
use App\Models\DictionaryCategory;
use App\Rules\NotRootCategory;
use App\Services\AdminImageStore;
use App\Support\YoutubeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * カクテルの新規・更新(POST /api/admin/cocktails・PUT /api/admin/cocktails/{id}。基本情報は #291、材料は #292)の入力。
 *
 * 旧の EditCocktailValidate の規則に、長さの上限・ベースの規則と、材料の件数の上限・ノードと銘柄の規則を足したもの。
 * 画像を上げるときは multipart で送る(PHP は PUT の multipart を読まないので、更新は POST に `_method=PUT` を付けて送る)。
 */
class SaveCocktailRequest extends FormRequest
{
    use AdminFormInput;

    /**
     * 材料の行の数の下限(旧と同じ)と上限(docs/decisions/admin-screens.md の決まったこと 5)。
     */
    public const MIN_INGREDIENTS = 2;

    public const MAX_INGREDIENTS = 20;

    /**
     * multipart(画像つき)では真偽値が文字列の "true"・"false" で届くので、boolean の規則が読める 1・0 に直す。
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanStrings(['is_major', 'is_draft']);

        // 材料の行の「任意」も、multipart では文字列で届く
        $ingredients = $this->input('ingredients');
        if (is_array($ingredients)) {
            $this->merge(['ingredients' => array_map(function (mixed $ingredient): mixed {
                $value = is_array($ingredient) ? self::booleanFromString($ingredient['is_optional'] ?? null) : null;

                return $value === null ? $ingredient : [...$ingredient, 'is_optional' => $value];
            }, $ingredients)]);
        }
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
            // 材料の行(#292)。並びは配列の順で、保存のときに position を 1 から振る
            'ingredients' => ['required', 'array', 'list', 'min:'.self::MIN_INGREDIENTS, 'max:'.self::MAX_INGREDIENTS],
            // 材料のノード。どの深さのノードでもよいが、根(旧の種別)は選べない(#153)
            'ingredients.*.category_id' => ['required', 'integer', 'exists:categories,id', app(NotRootCategory::class)],
            // 銘柄。決めない材料では空。銘柄があれば、ノードは銘柄の所属ノードにする(#19)
            'ingredients.*.brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            // 量。書式は確かめず、保存のときに整える(docs/decisions/admin-screens.md の決まったこと 4。旧と同じ)。
            // 整えた後の長さ(列の長さ)は保存のときに確かめる
            'ingredients.*.amount' => ['required', 'string', 'max:'.CocktailIngredient::AMOUNT_MAX_LENGTH],
            // 任意の材料か。送らなければ任意でない(旧と同じ)
            'ingredients.*.is_optional' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * YouTube の URL が読めるかを、ほかの項目の誤りと一緒に返す。
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->youtubeValidation(), $this->brandNodeValidation(...)];
    }

    /**
     * 銘柄のある材料の行で、ノードが銘柄の所属ノードかを確かめる(docs/schema.md の「材料が銘柄を持つときのノード」。#19)。
     *
     * ノード・銘柄のどちらかにもう誤りがある行は見ない(無い ID などの誤りだけを返す)。
     */
    private function brandNodeValidation(Validator $validator): void
    {
        $ingredients = $this->input('ingredients');
        if (! is_array($ingredients)) {
            return;
        }

        // 行の番号 → [ノードの ID, 銘柄の ID]。ここまでの規則を通った行だけ(ID は整数か数字の文字列)
        $pairs = [];
        $errors = $validator->errors();
        foreach ($ingredients as $index => $ingredient) {
            $categoryId = is_array($ingredient) ? ($ingredient['category_id'] ?? null) : null;
            $brandId = is_array($ingredient) ? ($ingredient['brand_id'] ?? null) : null;
            if (! is_numeric($categoryId) || ! is_numeric($brandId)
                || $errors->has("ingredients.{$index}.category_id") || $errors->has("ingredients.{$index}.brand_id")) {
                continue;
            }
            $pairs[$index] = [(int) $categoryId, (int) $brandId];
        }
        if ($pairs === []) {
            return;
        }

        $brands = Brand::query()->whereKey(array_column($pairs, 1))->get()->keyBy('id');
        foreach ($pairs as $index => [$categoryId, $brandId]) {
            $brand = $brands->get($brandId);
            if ($brand !== null && $brand->category_id !== $categoryId) {
                $validator->errors()->add(
                    "ingredients.{$index}.category_id",
                    "銘柄「{$brand->name}」を選んだ材料のノードは、銘柄の所属ノードにしてください。",
                );
            }
        }
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
