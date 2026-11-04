<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CategoryDeleteBlocker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Http\Resources\Admin\CategoryTreeItemResource;
use App\Models\Category;
use App\Services\AdminCategoryWriter;
use App\Services\CategoryNotDeletableException;
use App\Services\CategoryTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * 管理画面のカテゴリ木のノード(#121。画面は #123・#308〜#310)。旧の DetailEditController@category・middle の置き換え。
 * 旧はカテゴリとミドルを別の画面・API で保存していたが、任意の深さのノード 1 つにまとめる。削除は旧に無い。
 */
class CategoryController extends Controller
{
    /**
     * 木全体。根から一番深い段まで入れ子で、兄弟の並び順に返す。
     */
    public function index(CategoryTree $tree): AnonymousResourceCollection
    {
        return CategoryTreeItemResource::collection($tree->forest());
    }

    /**
     * 編集で開くノード。
     */
    public function show(int $category): CategoryResource
    {
        return CategoryResource::make(Category::query()->findOrFail($category));
    }

    /**
     * ノードを作る。英名が同じ親の下のほかのノードと同じなら、保存したうえで warnings に入れて返す。
     */
    public function store(SaveCategoryRequest $request, AdminCategoryWriter $writer): JsonResponse
    {
        $saved = $writer->save(null, $request);

        return CategoryResource::make($saved)
            ->additional(['warnings' => $writer->duplicateWarnings($saved)])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * ノードを書き換える。親を変えたときは部分木ごと移す。
     * 英名が同じ親の下のほかのノードと同じなら、保存したうえで warnings に入れて返す。
     */
    public function update(SaveCategoryRequest $request, AdminCategoryWriter $writer, int $category): CategoryResource
    {
        $saved = $writer->save(Category::query()->findOrFail($category), $request);

        return CategoryResource::make($saved)->additional(['warnings' => $writer->duplicateWarnings($saved)]);
    }

    /**
     * ノードを消す。子・銘柄・材料・ベース・アフィリエイトのどれかがあれば消さず、409 で理由をすべて返す。
     */
    public function destroy(AdminCategoryWriter $writer, int $category): Response|JsonResponse
    {
        try {
            $writer->delete(Category::query()->findOrFail($category));
        } catch (CategoryNotDeletableException $e) {
            return response()->json([
                'message' => 'このノードは消せません',
                // 消せない理由。code は children(子のノード)・brands(銘柄)・ingredients(材料)・base_cocktails(ベース)・affiliates(アフィリエイト)
                /** @var list<array{code: 'children'|'brands'|'ingredients'|'base_cocktails'|'affiliates', message: string}> */
                'reasons' => array_map(
                    fn (CategoryDeleteBlocker $blocker): array => ['code' => $blocker->value, 'message' => $blocker->message()],
                    $e->blockers,
                ),
            ], 409);
        }

        return response()->noContent();
    }
}
