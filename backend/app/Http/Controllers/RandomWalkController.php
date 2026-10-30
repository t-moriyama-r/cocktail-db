<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;

/**
 * ランダムウォーク(ヘッダーの「現在の登録数」のリンク先。#45)。旧 CommonController@RandomWalk。
 *
 * 行き先の種類は、カクテル・ノード・銘柄を 3 分の 1 ずつで選ぶ(旧はカクテル・カテゴリ・ミドル・銘柄を 4 分の 1 ずつ。
 * docs/decisions/public-detail.md の決まったこと 4)。
 */
class RandomWalkController extends Controller
{
    /**
     * 行き先の種類を 1 つ選び、その中からランダムに 1 件の ID を返す。
     *
     * - cocktail: 公開しているカクテル(下書きは選ばない。旧も is_draft = 0 で絞っていた)
     * - category: 根でないノード。旧の行き先はカテゴリとミドルで、根(旧の種別)は選ばれなかった
     * - brand: すべての銘柄
     *
     * 選んだ種類に 1 件も無ければ 404。
     *
     * @return array{type: 'cocktail'|'category'|'brand', id: int}
     *
     * @throws ModelNotFoundException<Cocktail|Category|Brand> 選んだ種類に 1 件も無いとき
     */
    public function __invoke(CategoryTree $tree): array
    {
        /** @var 'cocktail'|'category'|'brand' $type */
        $type = Arr::random(['cocktail', 'category', 'brand']);

        $query = match ($type) {
            'cocktail' => Cocktail::query()->published(),
            'category' => Category::query()->whereNotIn((new Category)->qualifyColumn('id'), $tree->rootIds()),
            'brand' => Brand::query(),
        };

        return ['type' => $type, 'id' => $query->inRandomOrder()->firstOrFail()->id];
    }
}
