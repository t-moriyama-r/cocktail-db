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
use Illuminate\Http\Request;
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
     * 名前・英名の重なりの問い合わせ(#673)。ノードの編集画面で、名前・英名を入れたそばに「同じ名前が存在しています」を出すため。
     * 旧 DetailEditController@duplicate_check のカテゴリ・ミドルの分の置き換え。
     *
     * 比べる相手は parent_id の子(送らないか空なら根どうし)のうち、exclude_id のノード(編集しているノード)のほか。
     * 比べ方は保存の API と同じ(名前は検索用の名前で、英名はそのまま)。名前・英名は、空なら比べない(どちらか 1 つは要る)。
     * 保存では名前の重なりは 422・英名の重なりは warnings だが、ここではどちらも warnings で返す(保存は止めない問い合わせのため)。
     *
     * @return array{warnings: list<array{field: string, message: string}>}
     */
    public function duplicates(Request $request, AdminCategoryWriter $writer): array
    {
        $request->validate([
            // 親のノードの ID。根どうしで比べるときは送らないか空にする
            'parent_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:categories,id'],
            // 名前。英名と、どちらか 1 つは要る
            'name' => ['required_without:name_eng', 'nullable', 'string', 'max:255'],
            // 英名。名前と、どちらか 1 つは要る
            'name_eng' => ['required_without:name', 'nullable', 'string', 'max:255'],
            // 編集しているノードの ID。新規なら送らない
            'exclude_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $parent = $request->filled('parent_id') ? Category::query()->findOrFail($request->integer('parent_id')) : null;

        return ['warnings' => $writer->nameDuplicates(
            $parent,
            $request->filled('name') ? $request->string('name')->toString() : null,
            $request->filled('name_eng') ? $request->string('name_eng')->toString() : null,
            $request->filled('exclude_id') ? $request->integer('exclude_id') : null,
        )];
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
