<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CocktailListItemResource;
use App\Models\Cocktail;
use App\Support\AdminListName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CocktailIndexController extends Controller
{
    /**
     * 1 ページの件数の既定と上限(#284 の 6: 50 件ずつ)。
     */
    public const PER_PAGE = 50;

    /**
     * 管理画面のカクテルの一覧。公開済みか下書きのどちらかを、名前の昇順で 1 ページずつ返す。
     *
     * 名前はカタカナをひらがなに、全角の英数字を半角に、大文字を小文字にそろえて比べ、同じなら ID の順(旧の管理画面の
     * 一覧と同じ並び)。q は名前か英名のどこかに含むものに絞る(英名は大文字・小文字を区別しない)。
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            // published: 公開済み / draft: 下書き
            'status' => ['required', 'string', 'in:published,draft'],
            // 名前か英名のどこかに含む語(#284 の 6。部分一致)
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE],
        ]);

        $q = $request->filled('q') ? $request->string('q')->toString() : null;
        $perPage = $request->integer('per_page', self::PER_PAGE);
        $page = $request->integer('page', 1);

        // 並び(旧の Helper.aiu)と絞り込み(旧の ListComponent.vue)は、旧が画面の JS でしていたそろえ方に合わせるため
        // AdminListName で比べる。SQL の照合順序では同じ並びにならないので、その状態のカクテル(旧で約 5,700 件)の
        // 一覧に使う列だけを読み、PHP で絞って並べてからページに切る。旧は ID の順に受け取って安定な並べ替えをしていた
        // ので、ID の順に読んで安定な並べ替え(sortBy)をかけ、名前が同じものを ID の順にする
        $cocktails = Cocktail::query()
            ->select(['id', 'name', 'name_eng', 'is_draft', 'updated_at'])
            ->where('is_draft', $request->string('status')->toString() === 'draft')
            ->orderBy('id')
            ->get();

        if ($q !== null) {
            $cocktails = $cocktails->filter(fn (Cocktail $c) => AdminListName::matches($q, $c->name, $c->name_eng));
        }

        $cocktails = $cocktails
            ->sortBy(fn (Cocktail $c) => AdminListName::sortKey($c->name), SORT_STRING)
            ->values();

        $paginator = new LengthAwarePaginator(
            $cocktails->forPage($page, $perPage)->values(),
            $cocktails->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return CocktailListItemResource::collection($paginator->withQueryString());
    }
}
