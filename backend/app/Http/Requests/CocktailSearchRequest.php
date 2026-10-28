<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Services\Search\CocktailSearchSort;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 条件検索(POST /api/cocktails/search)の条件(docs/search.md の「新しい条件との対応」)。
 *
 * 選んだノードの ID は、画面が部分木を親に畳めないと数百個になりうる(docs/decisions/search-screen.md の 3)ので、
 * GET のクエリではなく JSON の本文で受ける(#163 の「追記: API の形」)。
 */
class CocktailSearchRequest extends FormRequest
{
    /**
     * `category_ids` の件数の上限。画面で選べるノードの総数(畳まずに全部送っても)を十分に超える大きさ。
     */
    public const MAX_CATEGORY_IDS = 1000;

    /**
     * 1 ページの件数の既定と上限(#167。画面は 1 ページ 50 件。docs/decisions/search-screen.md の 5)。
     */
    public const PER_PAGE = 50;

    /**
     * 認証の要らない公開の検索なので、だれでも呼べる。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 方式。省くと or。and(#164)・having(#165)は、受ける形を決めてから足す。それまで受けると、
            // OR 検索の結果を黙って返してしまう
            'mode' => ['nullable', 'string', Rule::in(['or'])],
            // 選んだノード。根もよい(画面が部分木をすべて選んだときに畳んで送る。docs/search.md の「一致の定義」)
            // 件数の上限は、認証の要らない公開 API に巨大な配列を送られて検証と検索が重くなるのを防ぐため(docs/search.md)
            'category_ids' => ['required', 'array', 'list', 'max:'.self::MAX_CATEGORY_IDS],
            // `integer` は PHP の整数フィルターなので、true や 1.0 も 1 として通る。JSON の整数と数字の文字だけを受ける
            'category_ids.*' => ['required', 'integer', self::notBoolOrFloat(...)],
            // 並べ替える列(#167)。省くと ID の順
            'sort' => ['nullable', 'string', Rule::in(CocktailSearchSort::SORTS)],
            // 向き。省くと、列ごとに旧の画面で見出しを最初に押したときの向き(甘さ・評価・度数・動画は降順、ほかは昇順)
            'order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            // ページ(1 から)。最後のページより後は、空の data を返す
            'page' => ['nullable', 'integer', 'min:1', self::notBoolOrFloat(...)],
            // 1 ページの件数。省くと 50
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE, self::notBoolOrFloat(...)],
        ];
    }

    /**
     * `integer` は PHP の整数フィルターなので、true や 1.0 も 1 として通る。JSON の整数と数字の文字だけを受ける。
     */
    private static function notBoolOrFloat(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_bool($value) || is_float($value)) {
            $fail('The :attribute field must be an integer.');
        }
    }

    /**
     * 無いノードの ID が混ざっていたら弾く。
     *
     * `exists` を要素ごと(`category_ids.*`)に掛けると ID の数だけクエリが走るので、要素の形を確かめた後に
     * 1 本のクエリでまとめて数える。ほかの検証で落ちているときは数えない(入れ子の配列などで数え方が決まらないため)。
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ids = array_values(array_unique(self::integers($this->input('category_ids'))));
                if (Category::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add('category_ids', '選んだノードに、無いノードが含まれています。');
                }
            },
        ];
    }

    /**
     * 選んだノードの ID。
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return self::integers($this->validated('category_ids'));
    }

    /**
     * 並べ替える列。省くと ID の順。
     */
    public function sort(): string
    {
        $sort = $this->validated('sort');

        return is_string($sort) ? $sort : 'id';
    }

    /**
     * 並べ替える向き(asc / desc)。省くと列ごとの既定の向き。
     */
    public function order(): string
    {
        $order = $this->validated('order');

        return is_string($order) ? $order : CocktailSearchSort::defaultOrder($this->sort());
    }

    /**
     * ページ(1 から)。省くと 1。
     */
    public function page(): int
    {
        return self::integers([$this->validated('page')])[0] ?? 1;
    }

    /**
     * 1 ページの件数。省くと 50。
     */
    public function perPage(): int
    {
        return self::integers([$this->validated('per_page')])[0] ?? self::PER_PAGE;
    }

    /**
     * 検証(`integer`)を通った値を int にそろえる。JSON の数でも、数字の文字でも通るため。
     *
     * @return list<int>
     */
    private static function integers(mixed $values): array
    {
        $integers = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                $integers[] = $integer;
            }
        }

        return $integers;
    }
}
