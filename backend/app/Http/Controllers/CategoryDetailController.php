<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryDetailResource;
use App\Models\Category;

class CategoryDetailController extends Controller
{
    /**
     * ノードの詳細を返す。基本情報・根からのパンくず・子のノード・有名な銘柄と、そのノードの配下すべて(自分を含む)の銘柄。
     *
     * 旧のカテゴリ(DetailController@category)とミドル(@middle)の詳細を、任意の深さのノードで 1 つにしたもの。
     * 根(大元)も返す。無いノードは 404(旧は 500 だった。docs/decisions/category-detail.md)。
     */
    public function __invoke(int $category): CategoryDetailResource
    {
        return CategoryDetailResource::make(Category::query()->findOrFail($category));
    }
}
