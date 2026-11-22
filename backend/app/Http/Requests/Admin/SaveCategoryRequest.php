<?php

namespace App\Http\Requests\Admin;

use App\Enums\CategoryRole;
use App\Http\Requests\Admin\Concerns\AdminFormInput;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\AdminImageStore;
use App\Services\CategoryTree;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * カテゴリ木のノードの新規・更新(POST /api/admin/categories・PUT /api/admin/categories/{id}。#121)の入力。
 *
 * 旧の EditCategoryValidate・EditMiddleValidate の規則(旧のカテゴリとミドルは同じ項目)に、長さの上限・根の役割・
 * 親の付け替えの規則を足したもの。画像を上げるときは multipart で送る(PHP は PUT の multipart を読まないので、
 * 更新は POST に `_method=PUT` を付けて送る)。
 *
 * 有名な銘柄(docs/decisions/strength.md)は、既存の銘柄を famous_brand_id で選ぶか、brand_* の欄でその場で作る。
 * 選べるのはそのノードの部分木の銘柄だけ。必須かどうかは、親の根の役割で決まる(下の famousBrandMode())。
 */
class SaveCategoryRequest extends FormRequest
{
    use AdminFormInput;

    /**
     * 有名な銘柄を必須にする・書き始めたときだけ必須にする・入れさせない、の区別。
     */
    private const FAMOUS_BRAND_REQUIRED = 'required';

    private const FAMOUS_BRAND_IF_STARTED = 'if_started';

    private const FAMOUS_BRAND_PROHIBITED = 'prohibited';

    /**
     * その場で作る銘柄の欄のうち、書き始めたとみなす欄(旧の is_kind5 のときの判定と同じ 4 つ)。
     */
    private const BRAND_STARTED_FIELDS = ['brand_name', 'brand_name_eng', 'brand_strength', 'brand_description'];

    /**
     * multipart(画像つき)では真偽値が文字列の "true"・"false" で届くので、boolean の規則が読める 1・0 に直す。
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanStrings(['is_major', 'is_like', 'is_powder', 'brand_is_major', 'brand_is_like']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brandRequired = match ($this->famousBrandMode()) {
            self::FAMOUS_BRAND_REQUIRED => ['required_without:famous_brand_id'],
            self::FAMOUS_BRAND_IF_STARTED => $this->brandStarted() ? ['required_without:famous_brand_id'] : [],
            default => [],
        };
        $brandProhibited = $this->famousBrandMode() === self::FAMOUS_BRAND_PROHIBITED ? ['prohibited'] : [];

        return [
            // 親。空(null)なら根を作る・根にする。更新で変えると、部分木ごと移す(CategoryTree::moveSubtree)
            'parent_id' => ['present', 'nullable', 'integer', 'exists:categories,id'],
            // 根の役割(docs/decisions/category-root-role.md)。根にだけ入れられる(下の after())
            'role' => ['nullable', Rule::enum(CategoryRole::class)],
            'name' => ['required', 'string', 'max:255'],
            'name_eng' => ['required', 'string', 'max:255'],
            // 説明。保存のときに記法へ変換する(DescriptionMarkup。#287)
            'description' => ['required', 'string'],
            'is_major' => ['required', 'boolean'],
            'is_like' => ['required', 'boolean'],
            'is_powder' => ['required', 'boolean'],
            ...$this->youtubeRules(),
            // 画像のリンクの文字と URL
            'image_txt' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            ...AdminImageStore::rules(),

            // 有名な銘柄に選ぶ既存の銘柄。そのノードの部分木の銘柄だけ(下の after())。根には入れられない
            'famous_brand_id' => [...$brandProhibited, 'nullable', 'integer', 'exists:brands,id'],
            // その場で作る銘柄(旧の brand_*)。famous_brand_id を選んだときは使わない(旧と同じ)
            'brand_name' => [...$brandRequired, ...$brandProhibited, 'nullable', 'string', 'max:255'],
            'brand_name_eng' => [...$brandRequired, ...$brandProhibited, 'nullable', 'string', 'max:255'],
            'brand_strength' => [...$brandRequired, ...$brandProhibited, 'nullable', 'numeric', 'between:0,100'],
            'brand_description' => [...$brandRequired, ...$brandProhibited, 'nullable', 'string'],
            'brand_is_major' => [...$brandRequired, 'nullable', 'boolean'],
            'brand_is_like' => [...$brandRequired, 'nullable', 'boolean'],
            ...$this->youtubeRules('brand_'),
            'brand_image_txt' => ['nullable', 'string', 'max:255'],
            'brand_image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'brand_image' => AdminImageStore::fileRules(),
        ];
    }

    /**
     * 検証のメッセージに出す欄の名前(#692。docs/decisions/validation-messages.md)。画面の欄の名前に合わせる。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parent_id' => '親',
            'role' => '役割',
            'name' => '名前',
            'name_eng' => '英名',
            'description' => '説明',
            'is_major' => 'メジャー',
            'is_like' => 'お気に入り',
            'is_powder' => '粉末',
            ...$this->youtubeAttributes(),
            ...$this->imageAttributes(),
            'famous_brand_id' => '有名な銘柄',
            'brand_name' => '有名な銘柄の名前',
            'brand_name_eng' => '有名な銘柄の英名',
            'brand_strength' => '有名な銘柄の度数',
            'brand_description' => '有名な銘柄の説明',
            'brand_is_major' => '有名な銘柄のメジャー',
            'brand_is_like' => '有名な銘柄のお気に入り',
            ...$this->youtubeAttributes('brand_', '有名な銘柄の'),
            'brand_image_txt' => '有名な銘柄のリンクテキスト',
            'brand_image_url' => '有名な銘柄のリンク URL',
            'brand_image' => '有名な銘柄の画像',
        ];
    }

    /**
     * YouTube の URL が読めるか、根の役割・親・有名な銘柄が木の上で合っているかを、ほかの項目の誤りと一緒に返す。
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->youtubeValidation(),
            $this->youtubeValidation('brand_'),
            fn (Validator $validator) => $this->treeValidation($validator),
        ];
    }

    /**
     * 更新するノード。新規なら null。
     */
    public function targetNode(): ?Category
    {
        $id = $this->route('category');

        return is_numeric($id) ? Category::query()->find((int) $id) : null;
    }

    /**
     * 送られた親。根にするなら null。
     */
    public function parentNode(): ?Category
    {
        $id = $this->input('parent_id');

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? Category::query()->find((int) $id) : null;
    }

    /**
     * その場で作る銘柄を、書き始めているか(旧の is_kind5 のときの判定)。
     */
    public function brandStarted(): bool
    {
        foreach (self::BRAND_STARTED_FIELDS as $field) {
            if ($this->filled($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 有名な銘柄をどう扱うか。根には入れさせない(根は材料にならず、度数の計算に出てこない。docs/decisions/strength.md の
     * 「根のノード」)。根の役割が null の部分木では必須、ソフトドリンク・その他では書き始めたときだけ必須(旧と同じ)。
     *
     * 親の ID が読めないとき(親の規則が 422 にする)は、有名な銘柄の欄に誤りを重ねないよう、書き始めたときだけ必須にする。
     */
    private function famousBrandMode(): string
    {
        if ($this->input('parent_id') === null) {
            return self::FAMOUS_BRAND_PROHIBITED;
        }
        $parent = $this->parentNode();
        if ($parent === null) {
            return self::FAMOUS_BRAND_IF_STARTED;
        }

        return app(CategoryTree::class)->isFamousBrandOptional($parent)
            ? self::FAMOUS_BRAND_IF_STARTED
            : self::FAMOUS_BRAND_REQUIRED;
    }

    private function treeValidation(Validator $validator): void
    {
        if ($validator->errors()->has('parent_id')) {
            return;
        }
        $tree = app(CategoryTree::class);
        $node = $this->targetNode();
        $parent = $this->parentNode();

        if ($parent !== null && $this->filled('role')) {
            $validator->errors()->add('role', '役割は根にだけ入れられます');
        }

        if ($node !== null && $parent !== null) {
            if (in_array($parent->id, $tree->subtreeIds($node), true)) {
                $validator->errors()->add('parent_id', '自分自身と、自分より下のノードは親にできません');
            } elseif ($node->role !== null) {
                $validator->errors()->add('parent_id', '役割を持つ根は、ほかのノードの下へ移せません');
            }
        }

        // 根へ移すノードを、カクテルの材料・ベースが直接指しているなら止める(根は材料・ベースに使えない。
        // docs/decisions/category-root-role.md の決まったこと 4。#122)。子孫は根にならないので見ない
        if ($node !== null && $parent === null && ! $tree->isRoot($node)) {
            if (CocktailIngredient::query()->where('category_id', $node->id)->exists()) {
                $validator->errors()->add('parent_id', 'カクテルの材料に使われているノードは、根にできません');
            }
            if (Cocktail::query()->where('base_category_id', $node->id)->exists()) {
                $validator->errors()->add('parent_id', 'カクテルのベースに使われているノードは、根にできません');
            }
        }

        if ($parent !== null && $this->filled('famous_brand_id') && ! $validator->errors()->has('famous_brand_id')) {
            $brand = Brand::query()->find($this->integer('famous_brand_id'));
            $inSubtree = $node !== null && $brand !== null && in_array($brand->category_id, $tree->subtreeIds($node), true);
            if (! $inSubtree) {
                $validator->errors()->add('famous_brand_id', '有名な銘柄は、このノードとその下のノードの銘柄から選んでください');
            }
        }
    }
}
