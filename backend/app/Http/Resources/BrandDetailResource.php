<?php

namespace App\Http\Resources;

use App\Models\Brand;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use App\Support\DescriptionParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * 銘柄の詳細(GET /api/brands/{id}。#43)。
 *
 * - パンくず(path)は、所属ノードの根からのパンくず。根が先頭で、所属ノードが末尾(CategoryTree::path)。旧はカテゴリ・
 *   ミドル・種別を別の項目で返していた
 * - 銘柄の一覧は、旧の 2 つ(同じミドルのほかの銘柄・同じカテゴリで別のミドルの銘柄)を任意の深さに広げたもの
 *   (docs/decisions/public-detail.md の決まったこと 2)。どちらも ID の順
 *   - same_node_brands: 所属ノードに直接ぶら下がる、ほかの銘柄(自分を除く)
 *   - parent_subtree_brands: 所属ノードの親と、親の配下すべてのノードのうち、所属ノードでないノードに所属する銘柄。
 *     所属ノードが根なら親が無いので空。親が根(大元)のときは、大元の配下すべてではなく、所属ノードの配下だけ(オーナーの判断。#43)
 *
 * 「これを使ったカクテル」は返さない。画面が #174 の銘柄起点の API で取る(ノードの詳細と同じ)。アフィリエイトも返さない。
 * 画面が GET /api/affiliates(#47)に銘柄の ID を渡して取る。
 * 説明文(description)は、文字とリンクの区切りの並び(DescriptionParser。#286)にして返す。画像(image)は保存した値のまま返す。
 *
 * @property Brand $resource
 */
class BrandDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource->loadMissing(['category.parent']);
        // 所属ノードは外部キー(NOT NULL・RESTRICT)で必ずある
        $node = $brand->category ?? throw new LogicException("銘柄 {$brand->id} の所属ノードが無い");
        $parent = $node->parent;
        $parser = app(DescriptionParser::class);

        $sameNodeBrands = BrandSummaryResource::withPath(Brand::query())
            ->where('category_id', $node->id)
            ->whereKeyNot($brand->id)
            ->orderBy('id')
            ->get();

        // 親が根(大元)のときは、大元の配下すべてではなく、所属ノードの配下だけにする(旧のミドルの無い銘柄と同じ)
        $scope = $parent === null ? null : ($parent->parent === null ? $node : $parent);

        $parentSubtreeBrands = $scope === null
            ? collect()
            : BrandSummaryResource::withPath(Brand::query())
                ->whereIn('category_id', CategoryClosure::query()->select('descendant_id')->where('ancestor_id', $scope->id))
                ->where('category_id', '<>', $node->id)
                ->orderBy('id')
                ->get();

        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'name_eng' => $brand->name_eng,
            // 度数(%)
            'strength' => (float) $brand->volume,
            // 型は DescriptionParser::parse の戻り値。Scramble が app() の戻り値から型を読めないので、ここに書く
            /** @var list<array{type: 'text', text: string}|array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}>|null */
            'description' => $parser->parse($brand->description),
            'is_major' => $brand->is_major,
            'is_like' => $brand->is_like,
            'youtube' => $brand->youtube,
            'youtube_created_at' => $brand->youtube_created_at,
            'youtube_start_seconds' => $brand->youtube_start_seconds,
            'image' => $brand->image,
            'image_txt' => $brand->image_txt,
            'image_url' => $brand->image_url,
            'created_at' => $brand->created_at,
            'updated_at' => $brand->updated_at,
            // 根が先頭で、所属ノードが末尾
            'path' => NodeRefResource::collection(app(CategoryTree::class)->path($node)),
            'same_node_brands' => BrandSummaryResource::collection($sameNodeBrands),
            'parent_subtree_brands' => BrandSummaryResource::collection($parentSubtreeBrands),
        ];
    }
}
