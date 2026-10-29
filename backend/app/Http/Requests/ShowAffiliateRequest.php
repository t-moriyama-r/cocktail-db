<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 商品の欄に出すアフィリエイトの 1 件(GET /api/affiliates。#47)。
 *
 * 旧(AffiliateValidate)と同じく、ノードの ID はいつも要る。銘柄の ID は銘柄の詳細のときだけ付け、0 は付けないのと
 * 同じに扱う(旧の画面は、銘柄が無いときに 0 を送っていた)。
 */
class ShowAffiliateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function categoryId(): int
    {
        return $this->integer('category_id');
    }

    /**
     * 銘柄の ID。付いていない・0 なら null。
     */
    public function brandId(): ?int
    {
        $brandId = $this->integer('brand_id');

        return $brandId > 0 ? $brandId : null;
    }
}
