<?php

namespace App\Http\Resources;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use App\Support\DescriptionParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ノードの詳細(GET /api/categories/{id}。#42)。
 *
 * - パンくず(path)は根が先頭で、このノードが末尾(CategoryTree::path)
 * - 子のノード(children)は直下だけを、兄弟の並び順で返す(CategoryTree::children)
 * - 銘柄の一覧(brands)は、このノードと配下すべてのノードの銘柄を ID の順で返す(docs/decisions/public-detail.md の決まったこと 2)
 *
 * 「これを使ったカクテル」は返さない。画面は旧と同じくランダムの 5 件と「再取得」で、#174 のノード起点の API で取る
 * (docs/decisions/public-detail.md の決まったこと 1)。アフィリエイトも返さない。画面が GET /api/affiliates(#47)で取る。
 * 説明文(description)は、文字とリンクの区切りの並び(DescriptionParser。#286)にして返す。画像(image)は保存した値のまま返す。
 *
 * @property Category $resource
 */
class CategoryDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $node = $this->resource;
        $tree = app(CategoryTree::class);
        $parser = app(DescriptionParser::class);

        $brands = BrandSummaryResource::withPath(Brand::query())
            ->whereIn('category_id', CategoryClosure::query()->select('descendant_id')->where('ancestor_id', $node->id))
            ->orderBy('id')
            ->get();

        $famousBrand = $node->famous_brand_id === null
            ? null
            : BrandSummaryResource::withPath(Brand::query())->find($node->famous_brand_id);

        return [
            'id' => $node->id,
            'name' => $node->name,
            'name_eng' => $node->name_eng,
            // 型は DescriptionParser::parse の戻り値。Scramble が app() の戻り値から型を読めないので、ここに書く
            /** @var list<array{type: 'text', text: string}|array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}>|null */
            'description' => $parser->parse($node->description),
            'is_major' => $node->is_major,
            'is_like' => $node->is_like,
            'youtube' => $node->youtube,
            'youtube_created_at' => $node->youtube_created_at,
            'youtube_start_seconds' => $node->youtube_start_seconds,
            'image' => $node->image,
            'image_txt' => $node->image_txt,
            'image_url' => $node->image_url,
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
            // 根が先頭で、このノードが末尾
            'path' => NodeRefResource::collection($tree->path($node)),
            'children' => NodeRefResource::collection($tree->children($node)),
            'famous_brand' => $famousBrand === null ? null : BrandSummaryResource::make($famousBrand),
            'brands' => BrandSummaryResource::collection($brands),
        ];
    }
}
