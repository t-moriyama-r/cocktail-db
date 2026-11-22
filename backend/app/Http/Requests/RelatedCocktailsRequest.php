<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 別のカクテル(GET /api/cocktails/{id}/related。#174)の件数。
 */
class RelatedCocktailsRequest extends FormRequest
{
    /**
     * 1 回に返す件数の上限(#174 の受け入れ条件)。旧は上限が無かった(docs/decisions/related-cocktails.md)。
     */
    public const MAX_LIMIT = 50;

    /**
     * 認証の要らない公開の API なので、だれでも呼べる。
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
            // 旧と同じく必須。旧の画面は 5(材料が似たカクテル・同じベース・これを使ったカクテル)か 7(技法・グラス)を送っていた
            'limit' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * 返す件数。
     */
    public function limit(): int
    {
        return $this->integer('limit');
    }
}
