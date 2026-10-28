<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 名前の横断検索の入力(GET /api/search?q=…。#172)。
 *
 * 旧の SearchFromWord と同じく、必須・255 文字まで。旧は記号を禁じる not_regex も書いていたが、文字クラスが途中で
 * 閉じていて何にも当たらず、記号も通していた(docs/decisions/name-search.md の決まったこと 2)ので、ここでも禁じない。
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
}
