<?php

namespace App\Http\Requests;

use App\Services\Search\NameSearch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 名前の横断検索の入力(GET /api/search?q=…。#172)。
 *
 * 旧の SearchFromWord と同じく、必須・255 文字まで。旧は記号を禁じる not_regex も書いていたが、文字クラスが途中で
 * 閉じていて何にも当たらず、記号も通していた。新は記号は禁じず(% と _ はふつうの文字として探す)、語が 1 つも
 * 残らない入力(・ だけなど)を 422 にする(docs/decisions/name-search.md の決まったこと 2)。
 */
class NameSearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * 語に分けて検索用の形にそろえると 1 つも残らない入力(区切りの文字だけ)は、全件を返さずに 422 にする。
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $q = $this->input('q');

                if (is_string($q) && ! $validator->errors()->has('q') && NameSearch::words($q) === []) {
                    $validator->errors()->add('q', 'q に探せる文字がありません。');
                }
            },
        ];
    }
}
