<?php

namespace App\Http\Resources\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Services\AdminCategoryWriter;
use App\Services\CategoryTree;
use App\Services\DescriptionMarkup;
use App\Support\YoutubeInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のノードの編集で開く値(GET /api/admin/categories/{id} と、新規・更新の応答。#121。画面は #308〜#310)。
 *
 * 説明は、編集で開く形(記法に指す先の名前を添えたもの。DescriptionMarkup::withNotes)で返す。
 * YouTube の開始位置は、画面の欄に合わせて時・分・秒に分けて返す。銘柄(BrandResource)と同じ形にそろえる。
 * 画面に出す、有名な銘柄の候補(このノードの部分木の銘柄)・子のノード・直下の銘柄・消せない理由も一緒に返す。
 *
 * @property Category $resource
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array{id: int, parent_id: int|null, path: list<array{id: int, name: string}>, role: string|null, root_role: string|null, famous_brand_requirement: 'required'|'optional'|'none', name: string, name_eng: string, description: string|null, is_major: bool, is_like: bool, is_powder: bool, famous_brand_id: int|null, famous_brand_candidates: list<array{id: int, name: string, name_eng: string}>, children: list<array{id: int, name: string, name_eng: string}>, brands: list<array{id: int, name: string, name_eng: string}>, youtube: string|null, youtube_start_hour: int|null, youtube_start_minute: int|null, youtube_start_second: int|null, youtube_created_at: string|null, image: string|null, image_txt: string|null, image_url: string|null, delete_blockers: list<array{code: 'children'|'brands'|'ingredients'|'base_cocktails'|'affiliates'|'referenced_by_cocktail'|'referenced_by_node'|'referenced_by_method'|'referenced_by_brand'|'referenced_by_dictionary', message: string, referrers?: list<array{id: int, name: string}>}>, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $node = $this->resource;
        $tree = app(CategoryTree::class);
        $path = $tree->path($node);
        $parent = $path->count() > 1 ? $path[$path->count() - 2] : null;
        $start = YoutubeInput::splitSeconds($node->youtube_start_seconds);
        $brandSummary = fn (Brand $brand): array => ['id' => $brand->id, 'name' => $brand->name, 'name_eng' => $brand->name_eng];

        return [
            'id' => $node->id,
            // 親。根なら null
            /** @var int|null */
            'parent_id' => $parent?->id,
            // 根から親までのパンくず(自分は含まない)
            /** @var list<array{id: int, name: string}> */
            'path' => array_values($path->slice(0, -1)->map(fn (Category $step): array => ['id' => $step->id, 'name' => $step->name])->all()),
            // 根の役割。根だけが値を持つ
            /** @var 'soft_drink'|'other'|null */
            'role' => $node->role?->value,
            // ノードの根の役割(根なら自分の役割)
            /** @var 'soft_drink'|'other'|null */
            'root_role' => $tree->rootRole($node)?->value,
            // 有名な銘柄が必須か。required(根の役割が無い部分木)・optional(ソフトドリンク・その他)・none(根。入れられない)
            'famous_brand_requirement' => match (true) {
                $parent === null => 'none',
                $tree->isFamousBrandOptional($node) => 'optional',
                default => 'required',
            },
            'name' => $node->name,
            'name_eng' => $node->name_eng,
            // 編集で開く形(記法に指す先の名前を添えたもの)
            /** @var string|null */
            'description' => app(DescriptionMarkup::class)->withNotes($node->description),
            'is_major' => $node->is_major,
            'is_like' => $node->is_like,
            'is_powder' => $node->is_powder,
            'famous_brand_id' => $node->famous_brand_id,
            // 有名な銘柄に選べる銘柄(このノードとその下のノードの銘柄。ID の順)
            /** @var list<array{id: int, name: string, name_eng: string}> */
            'famous_brand_candidates' => array_values(Brand::query()
                ->whereIn('category_id', $tree->subtreeIds($node))
                ->orderBy('id')
                ->get(['id', 'name', 'name_eng'])
                ->map($brandSummary)
                ->all()),
            // すぐ下の子(兄弟の並び順)
            /** @var list<array{id: int, name: string, name_eng: string}> */
            'children' => array_values($tree->children($node)
                ->map(fn (Category $child): array => ['id' => $child->id, 'name' => $child->name, 'name_eng' => $child->name_eng])
                ->all()),
            // このノードに直接ぶら下がる銘柄(ID の順)
            /** @var list<array{id: int, name: string, name_eng: string}> */
            'brands' => array_values($node->brands()->orderBy('id')->get(['id', 'name', 'name_eng'])->map($brandSummary)->all()),
            // 動画 ID
            'youtube' => $node->youtube,
            /** @var int|null */
            'youtube_start_hour' => $start['hour'],
            /** @var int|null */
            'youtube_start_minute' => $start['minute'],
            /** @var int|null */
            'youtube_start_second' => $start['second'],
            // 動画を入れた・変えた日時
            'youtube_created_at' => $node->youtube_created_at,
            'image' => $node->image,
            'image_txt' => $node->image_txt,
            'image_url' => $node->image_url,
            // 消せない理由(DELETE が 409 で返すものと同じ)。消せるなら空
            /** @var list<array{code: 'children'|'brands'|'ingredients'|'base_cocktails'|'affiliates'|'referenced_by_cocktail'|'referenced_by_node'|'referenced_by_method'|'referenced_by_brand'|'referenced_by_dictionary', message: string, referrers?: list<array{id: int, name: string}>}> */
            'delete_blockers' => app(AdminCategoryWriter::class)->whyNotDeletable($node)?->reasons() ?? [],
            'updated_at' => $node->updated_at?->toJSON(),
        ];
    }
}
