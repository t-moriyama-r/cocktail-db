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
     * `category_groups` のまとまりの個数の上限。まとまりごとに副問い合わせが 1 つ走るので、ID の総数とは別に絞る。
     * 現実のカクテルの材料は 20 に届かない(#164 のオーナーの判断)。
     */
    public const MAX_CATEGORY_GROUPS = 20;

    /**
     * `method_ids` の件数の上限(#166)。技法の数(旧は 6 つ)を十分に超える大きさ。`category_ids` と同じ理由で置く。
     */
    public const MAX_METHOD_IDS = 1000;

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
            // 方式。省くと or。having(#165)は手持ちの材料(category_ids)で作れるカクテル。and(#164)は
            // 選んだノードのまとまりの並び(category_groups)で受ける
            'mode' => ['nullable', 'string', Rule::in(['or', 'and', 'having'])],
            // 選んだノード(or では探す材料、having では手持ちの材料)。根もよい(画面が部分木をすべて選んだときに畳んで送る。docs/search.md の「一致の定義」)
            // 件数の上限は、認証の要らない公開 API に巨大な配列を送られて検証と検索が重くなるのを防ぐため(docs/search.md)
            // and はまとまりの並び(category_groups)で受けるので、送られたら黙って捨てずに弾く(空の配列も弾くため、prohibited ではなく missing)
            'category_ids' => ['required_unless:mode,and', 'missing_if:mode,and', 'array', 'list', 'max:'.self::MAX_CATEGORY_IDS],
            // `integer` は PHP の整数フィルターなので、true や 1.0 も 1 として通る。JSON の整数と数字の文字だけを受ける
            'category_ids.*' => ['required', 'integer', self::notBoolOrFloat(...)],
            // 選んだノードのまとまりの並び(and。#164)。画面が「同じ親から選んだもの」を 1 つのまとまりにして送り、
            // まとまりごとに「どれか 1 つを使う」で探す(docs/decisions/search-screen.md の 1)。まとまりの単位は画面が決める。
            // and 以外で送られたら、黙って捨てずに弾く。ID の総数の上限は category_ids と同じ(after で数える)
            'category_groups' => ['required_if:mode,and', 'missing_unless:mode,and', 'array', 'list', 'max:'.self::MAX_CATEGORY_GROUPS],
            // 空のまとまりは、どのカクテルも満たせない条件になるので弾く(画面は空のまとまりを送らない)
            // 1 つのまとまりの件数にも上限を掛け、after の総数の上限の前に巨大な配列を検証しないようにする
            'category_groups.*' => ['required', 'array', 'list', 'max:'.self::MAX_CATEGORY_IDS],
            'category_groups.*.*' => ['required', 'integer', self::notBoolOrFloat(...)],
            // 技法(#166)。カクテルの技法がこの中にあるものに絞る。省く・null・空の配列なら、すべての技法として扱う
            // (旧は 1 つ以上が必須だった。docs/decisions/search-screen.md の 6)。無い技法の ID は弾かず、何にも一致しない
            // (旧と同じ)。件数の上限は category_ids と同じ理由
            'method_ids' => ['nullable', 'array', 'list', 'max:'.self::MAX_METHOD_IDS],
            'method_ids.*' => ['required', 'integer', self::notBoolOrFloat(...)],
            // かんたん検索(#166)。真なら、材料の数(recipe_count。docs/decisions/recipe-count.md)が 3 以下のものに絞る。
            // 省くと絞らない(旧の画面の既定と同じ)。受ける値は旧と同じ Laravel の boolean(true・false・1・0・"1"・"0")
            'is_easy' => ['nullable', 'boolean'],
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

                $field = $this->isAnd() ? 'category_groups' : 'category_ids';
                $ids = $this->isAnd()
                    ? array_merge(...self::groups($this->input('category_groups')))
                    : self::integers($this->input('category_ids'));
                if (count($ids) > self::MAX_CATEGORY_IDS) {
                    $validator->errors()->add($field, '選んだノードは、全部で '.self::MAX_CATEGORY_IDS.' 個までです。');

                    return;
                }

                $ids = array_values(array_unique($ids));
                if (Category::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add($field, '選んだノードに、無いノードが含まれています。');
                }
            },
        ];
    }

    /**
     * AND 検索か。
     */
    public function isAnd(): bool
    {
        return $this->input('mode') === 'and';
    }

    /**
     * あるもの検索(手持ちの材料で作れるカクテル)か。
     */
    public function isHaving(): bool
    {
        return $this->validated('mode') === 'having';
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
     * 選んだノードのまとまりの並び(and)。
     *
     * @return list<list<int>>
     */
    public function categoryGroups(): array
    {
        return self::groups($this->validated('category_groups'));
    }

    /**
     * 検証を通ったまとまりの並びを、int の並びの並びにそろえる。
     *
     * @return list<list<int>>
     */
    private static function groups(mixed $groups): array
    {
        return array_map(self::integers(...), array_values(is_array($groups) ? $groups : []));
    }

    /**
     * 絞り込む技法の ID。省く・null・空の配列なら null(すべての技法)。
     *
     * @return list<int>|null
     */
    public function methodIds(): ?array
    {
        $ids = self::integers($this->validated('method_ids'));

        return $ids === [] ? null : $ids;
    }

    /**
     * かんたん検索(材料の数が 3 以下に絞る)か。省くと絞らない。
     */
    public function isEasy(): bool
    {
        return $this->boolean('is_easy');
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
