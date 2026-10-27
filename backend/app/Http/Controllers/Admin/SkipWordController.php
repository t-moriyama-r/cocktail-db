<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSkipWordRequest;
use App\Http\Resources\Admin\SkipWordResource;
use App\Models\SkipWord;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * 管理画面のスキップ語(#316)。旧は自動取り込みの画面から足すだけで、一覧・編集・削除は無かった。
 */
class SkipWordController extends Controller
{
    /**
     * スキップ語をすべて、ID の順で返す(旧で 5 件。ページに分けない)。
     */
    public function index(): AnonymousResourceCollection
    {
        return SkipWordResource::collection(SkipWord::query()->orderBy('id')->get());
    }

    /**
     * スキップ語を 1 語足す。
     */
    public function store(SaveSkipWordRequest $request): SkipWordResource
    {
        return SkipWordResource::make(SkipWord::query()->create($request->validated()));
    }

    /**
     * スキップ語を 1 語書き換える。
     */
    public function update(SaveSkipWordRequest $request, SkipWord $skipWord): SkipWordResource
    {
        $skipWord->update($request->validated());

        return SkipWordResource::make($skipWord);
    }

    /**
     * スキップ語を 1 語消す。
     */
    public function destroy(SkipWord $skipWord): Response
    {
        $skipWord->delete();

        return response()->noContent();
    }
}
