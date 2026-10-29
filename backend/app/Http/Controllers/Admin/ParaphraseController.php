<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveParaphraseRequest;
use App\Http\Resources\Admin\ParaphraseResource;
use App\Models\Paraphrase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * 管理画面の言い換え辞書(#316)。旧には保存の画面が無く(一覧を出してフォームに読み込むだけ)、ここで初めて編集できる。
 */
class ParaphraseController extends Controller
{
    /**
     * 1 ページの件数。
     */
    public const PER_PAGE = 50;

    /**
     * 言い換え辞書の一覧を、ID の順(足した順)で 1 ページずつ返す。
     *
     * q は、変換前・変換後・ミドルの名前のどこかに含む語(部分一致)。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = $request->filled('q') ? $request->string('q')->toString() : null;

        $paraphrases = Paraphrase::query()
            ->when($q !== null, function (Builder $query) use ($q) {
                $like = '%'.addcslashes((string) $q, '\\%_').'%';
                $query->where(fn (Builder $where) => $where
                    ->where('name', 'like', $like)
                    ->orWhere('converted', 'like', $like)
                    ->orWhere('middle', 'like', $like));
            })
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return ParaphraseResource::collection($paraphrases);
    }

    /**
     * 言い換え辞書の 1 語を返す。
     */
    public function show(Paraphrase $paraphrase): ParaphraseResource
    {
        return ParaphraseResource::make($paraphrase);
    }

    /**
     * 言い換え辞書に 1 語を足す。
     */
    public function store(SaveParaphraseRequest $request): ParaphraseResource
    {
        return ParaphraseResource::make(Paraphrase::query()->create($request->validated()));
    }

    /**
     * 言い換え辞書の 1 語を書き換える。
     */
    public function update(SaveParaphraseRequest $request, Paraphrase $paraphrase): ParaphraseResource
    {
        // 省かれたキーは null にそろえる(検証は middle 無しで解決しているので、古い middle を残さない)
        $paraphrase->update($request->validated() + ['middle' => null, 'command' => null, 'note' => null]);

        return ParaphraseResource::make($paraphrase);
    }

    /**
     * 言い換え辞書の 1 語を消す。
     */
    public function destroy(Paraphrase $paraphrase): Response
    {
        $paraphrase->delete();

        return response()->noContent();
    }
}
