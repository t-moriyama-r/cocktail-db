<?php

namespace App\Http\Requests;

use LogicException;

/**
 * ノード・銘柄・ベース・技法・グラスを起点にした別のカクテル(GET /api/cocktails/random。#174)の条件。
 *
 * 起点はちょうど 1 つ。旧(RandomByGetRandom)は複数を送ると base・category・cocktail・method・glass の順で
 * 先のものだけを使っていたが、旧の画面は 1 つしか送らないので、新は 2 つ以上を弾く(docs/decisions/related-cocktails.md)。
 */
class RandomCocktailsRequest extends RelatedCocktailsRequest
{
    /**
     * 起点の名前(クエリの名前)。
     */
    public const ORIGINS = ['category_id', 'brand_id', 'base_id', 'method_id', 'glass_id'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (self::ORIGINS as $origin) {
            $others = implode(',', array_diff(self::ORIGINS, [$origin]));
            $rules[$origin] = ['required_without_all:'.$others, 'prohibits:'.$others, 'integer', 'min:1'];
        }
        // 技法は旧も 1〜6(ある技法)だけを受けていた(旧の検証は max:6)。ほかの起点は、無い ID なら 0 件を返す(旧と同じ)
        $rules['method_id'][] = 'exists:methods,id';

        return $rules;
    }

    /**
     * 送られた起点の名前と ID。検証で、ちょうど 1 つあることを確かめてある。
     *
     * @return array{value-of<self::ORIGINS>, int}
     */
    public function origin(): array
    {
        foreach (self::ORIGINS as $origin) {
            if ($this->filled($origin)) {
                return [$origin, $this->integer($origin)];
            }
        }

        throw new LogicException('起点が無い(検証で弾いているはず)');
    }
}
