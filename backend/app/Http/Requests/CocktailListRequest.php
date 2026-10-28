<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * トップに出すカクテルの一覧の条件(GET /api/cocktails。#39。決めたことは docs/decisions/cocktail-lists.md)。
 */
class CocktailListRequest extends FormRequest
{
    /**
     * 件数を指定しないときの件数。
     */
    public const DEFAULT_LIMIT = 10;

    /**
     * 件数の上限(#39 の「やること」)。
     */
    public const MAX_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // recent: 更新の新しい順。recommend: 評価が 3 以上からランダム。random: ランダム
            'sort' => ['required', 'string', Rule::in(['recent', 'recommend', 'random'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * 並べ方。rules() で 3 つのどれかに絞ってある。
     */
    public function sort(): string
    {
        return $this->string('sort')->toString();
    }

    /**
     * 返す件数。
     */
    public function limit(): int
    {
        return $this->integer('limit', self::DEFAULT_LIMIT);
    }
}
