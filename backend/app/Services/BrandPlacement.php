<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 管理画面に出す、銘柄の所属のパンくずと、有名な銘柄として使われているか(#314・#64)。
 *
 * 一覧の 1 ページ分の銘柄の所属ノードと祖先を、まとめて引く。
 */
class BrandPlacement
{
    /**
     * 銘柄ごとの、所属ノードのパンくず(根を除き、浅い順)と、有名な銘柄として使われているか。
     *
     * パンくずのノードごとに、そのノードの有名な銘柄がこの銘柄かを付ける(画面でその段の名前の前に ★ を付ける)。
     * is_famous_brand は、パンくずの外のノードも含め、どこかのノードの有名な銘柄になっているか。
     *
     * @param  iterable<Brand>  $brands
     * @return array<int, array{path: list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}>, is_famous_brand: bool}>
     */
    public function forBrands(iterable $brands): array
    {
        $categoryIdByBrand = [];
        foreach ($brands as $brand) {
            $categoryIdByBrand[$brand->id] = $brand->category_id;
        }
        if ($categoryIdByBrand === []) {
            return [];
        }

        // 所属ノードと、その祖先(根から順)
        $nodes = Category::query()
            ->whereKey(array_values(array_unique($categoryIdByBrand)))
            ->with(['ancestors' => fn (BelongsToMany $ancestors) => $ancestors->orderByPivot('depth', 'desc')])
            ->get()
            ->keyBy('id');

        $famousBrandIds = Category::query()
            ->whereIn('famous_brand_id', array_keys($categoryIdByBrand))
            ->distinct()
            ->pluck('famous_brand_id')
            ->flip();

        $result = [];
        foreach ($categoryIdByBrand as $brandId => $categoryId) {
            $node = $nodes->get($categoryId);
            // 先頭は根なので除く(根の名前は画面のどこにも出さない。#283 の 3)
            $path = $node === null ? [] : array_slice([...$node->ancestors->all(), $node], 1);
            $result[$brandId] = [
                'path' => array_map(fn (Category $step): array => [
                    'id' => $step->id,
                    'name' => $step->name,
                    'name_eng' => $step->name_eng,
                    'is_famous_brand' => $step->famous_brand_id === $brandId,
                ], $path),
                'is_famous_brand' => $famousBrandIds->has($brandId),
            ];
        }

        return $result;
    }
}
