<?php

namespace App\Http\Resources\Admin;

use App\Enums\CocktailDuplicateMatch;
use App\Models\Cocktail;
use App\Services\DescriptionMarkup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 重複チェックで並べるカクテルの 1 件(GET /api/admin/cocktails/duplicates。#129)。旧 AutoInputDubs と同じく、
 * 画面で見比べるための名前・技法・下書きか・説明・補足と、材料(名前つき)を返す。
 *
 * @property Cocktail $resource
 */
class CocktailDuplicateResource extends JsonResource
{
    public function __construct(Cocktail $resource, private readonly CocktailDuplicateMatch $matchKind)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: int, name: string, name_eng: string|null, method_id: int, is_draft: bool, match: CocktailDuplicateMatch, description: string|null, note: string|null, ingredients: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        $cocktail = $this->resource;
        $markup = app(DescriptionMarkup::class);

        return [
            'id' => $cocktail->id,
            'name' => $cocktail->name,
            // 移した旧のカクテルには英名の無いものがある
            /** @var string|null */
            'name_eng' => $cocktail->name_eng,
            'method_id' => $cocktail->method_id,
            'is_draft' => $cocktail->is_draft,
            // 編集しているカクテルの保存してある材料との近さ。材料は旧のカテゴリ(根のすぐ下のノード)と量で比べる
            /** @var CocktailDuplicateMatch */
            'match' => $this->matchKind,
            // 記法に指す先の名前を添えた形(旧 Markup::at_id_append_note と同じく、画面で読める形)
            /** @var string|null */
            'description' => $markup->withNotes($cocktail->description),
            /** @var string|null */
            'note' => $markup->withNotes($cocktail->note),
            // 材料。レシピの順(position の昇順)
            'ingredients' => CocktailDuplicateIngredientResource::collection($cocktail->ingredients),
        ];
    }
}
