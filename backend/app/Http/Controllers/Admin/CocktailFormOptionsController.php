<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FormOptionResource;
use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Ice;
use App\Models\Method;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CocktailFormOptionsController extends Controller
{
    /**
     * カクテルの編集画面の選択肢(#291)。技法・グラス(用語辞典の分類「グラス」の項目)・氷を、それぞれ ID の順で返す。
     *
     * 旧は SearchController@getListForRecipe が、材料の選択肢と一緒にまとめて返していた。
     *
     * @return array{methods: AnonymousResourceCollection, glasses: AnonymousResourceCollection, ices: AnonymousResourceCollection}
     */
    public function __invoke(): array
    {
        return [
            'methods' => FormOptionResource::collection(Method::query()->orderBy('id')->get()),
            'glasses' => FormOptionResource::collection(
                Dictionary::query()->where('dictionary_category_id', DictionaryCategory::GLASS_ID)->orderBy('id')->get(),
            ),
            'ices' => FormOptionResource::collection(Ice::query()->orderBy('id')->get()),
        ];
    }
}
