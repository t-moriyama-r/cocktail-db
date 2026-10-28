<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DictionaryDeleteBlocker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDictionaryRequest;
use App\Http\Resources\Admin\DictionaryListCategoryResource;
use App\Http\Resources\Admin\DictionaryResource;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Services\AdminDictionaryWriter;
use App\Services\DictionaryNotDeletableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * 管理画面の用語辞典(#315。画面は #66)。旧は用語の保存を止めていた(旧 routes/web.php の「面倒なのでサポート切り」)ので、
 * 一覧のほかはここで初めて作る。
 */
class DictionaryController extends Controller
{
    /**
     * 用語辞典の一覧。分類を ID の順に、分類ごとの用語を名前の順で返す。
     *
     * 用語の無い分類も空の並びで返す(新規の画面で分類を選べるように。旧の一覧の画面は用語の無い分類を出さなかった)。
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = DictionaryCategory::query()
            ->with('dictionaries:id,dictionary_category_id,name,name_eng')
            ->orderBy('id')
            ->get();

        return DictionaryListCategoryResource::collection($categories);
    }

    /**
     * 編集で開く用語。
     */
    public function show(Dictionary $dictionary): DictionaryResource
    {
        return DictionaryResource::make($dictionary);
    }

    /**
     * 用語を作る。
     */
    public function store(SaveDictionaryRequest $request, AdminDictionaryWriter $writer): JsonResponse
    {
        return DictionaryResource::make($writer->save(null, $request))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 用語を書き換える。
     */
    public function update(SaveDictionaryRequest $request, AdminDictionaryWriter $writer, Dictionary $dictionary): DictionaryResource
    {
        return DictionaryResource::make($writer->save($dictionary, $request));
    }

    /**
     * 用語を消す。カクテルのグラスに使われている・技法を説明する用語になっているときは消さず、409 で理由を返す。
     */
    public function destroy(AdminDictionaryWriter $writer, Dictionary $dictionary): Response|JsonResponse
    {
        try {
            $writer->delete($dictionary);
        } catch (DictionaryNotDeletableException $e) {
            return response()->json([
                'message' => 'この用語は消せません',
                // 消せない理由。code は glass(カクテルのグラス)・method(技法を説明する用語)
                /** @var list<array{code: 'glass'|'method', message: string}> */
                'reasons' => array_map(
                    fn (DictionaryDeleteBlocker $blocker): array => ['code' => $blocker->value, 'message' => $blocker->message()],
                    $e->blockers,
                ),
            ], 409);
        }

        return response()->noContent();
    }
}
