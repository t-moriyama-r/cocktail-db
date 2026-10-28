<?php

namespace App\Http\Controllers;

use App\Http\Requests\CocktailListRequest;
use App\Http\Resources\CocktailListItemResource;
use App\Http\Resources\LatestVideoCocktailResource;
use App\Models\Cocktail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * トップに出すカクテルの件数・一覧・新着動画(#39。決めたことは docs/decisions/cocktail-lists.md)。
 *
 * どれも下書きを出さない(旧 CommonController の countCocktail・cocktailRecent・cocktailRecommend・cocktailRandom・getNewMovie)。
 */
class CocktailListController extends Controller
{
    /**
     * 公開しているカクテルの件数を返す(下書きは数えない)。
     *
     * @return array{count: int}
     */
    public function count(): array
    {
        return ['count' => Cocktail::query()->published()->count()];
    }

    /**
     * カクテルを、指定した並べ方で limit 件まで返す。1 件は一覧の表(#298)の 1 行の形。
     *
     * - recent: 更新日時(updated_at)の新しい順。同じ日時なら ID の大きい順
     * - recommend: 評価(rate)が 3 以上のカクテルから、ランダムに選ぶ(旧 cocktailRecommend)
     * - random: すべてのカクテルから、ランダムに選ぶ
     *
     * limit は 1〜50(省略すると 10)。sort・limit が外れていたら 422。
     */
    public function index(CocktailListRequest $request): AnonymousResourceCollection
    {
        $query = Cocktail::query()->published()->with(['baseCategory', 'method']);

        match ($request->sort()) {
            'recent' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'recommend' => $query->where('rate', '>=', 3)->inRandomOrder(),
            default => $query->inRandomOrder(),
        };

        return CocktailListItemResource::collection($query->limit($request->limit())->get());
    }

    /**
     * 動画の公開日時(youtube_created_at)がいちばん新しいカクテルを返す。同じ日時なら ID の大きいもの。
     *
     * 動画(youtube)の無いカクテルは選ばない。動画のある公開のカクテルが 1 件も無ければ 404。
     *
     * @throws ModelNotFoundException<Cocktail>
     */
    public function latestVideo(): LatestVideoCocktailResource
    {
        $cocktail = Cocktail::query()
            ->published()
            ->whereNotNull('youtube')
            ->where('youtube', '<>', '')
            ->orderByDesc('youtube_created_at')
            ->orderByDesc('id')
            ->firstOrFail();

        return LatestVideoCocktailResource::make($cocktail);
    }
}
