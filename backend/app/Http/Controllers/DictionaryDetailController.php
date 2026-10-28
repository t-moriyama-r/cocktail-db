<?php

namespace App\Http\Controllers;

use App\Http\Resources\DictionaryDetailResource;
use App\Models\Dictionary;

class DictionaryDetailController extends Controller
{
    /**
     * 用語の詳細。用語の種類(技法・グラス・そのほか)と、技法なら技法の ID も返す(docs/decisions/dictionary.md)。
     *
     * 無い用語は 404(旧は 500 だった)。
     */
    public function __invoke(int $dictionary): DictionaryDetailResource
    {
        return DictionaryDetailResource::make(Dictionary::query()->findOrFail($dictionary));
    }
}
