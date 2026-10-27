<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\NodeRefResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Paraphrase;
use App\Services\CategoryTree;
use App\Services\ParaphraseTargets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * 管理画面の言い換え辞書の 1 語(#316)。
 *
 * 画面で選んだ読み替え先を見せるため、converted・middle の名前を解決した先(resolved)も返す。ノードなら根からの
 * パンくず(末尾がそのノード)、銘柄なら所属するノードまでのパンくずと銘柄(カクテルの詳細の材料と同じ形)。
 * 名前は ID でなく名前のまま持つので、ノードの名前が後から変わると解決できなくなる。そのときは null。
 *
 * @property Paraphrase $resource
 */
class ParaphraseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $target = app(ParaphraseTargets::class)->resolve($this->resource->converted, $this->resource->middle);

        return [
            'id' => $this->resource->id,
            // 変換前の名前
            'name' => $this->resource->name,
            // 変換後の名前(ノードか銘柄)
            'converted' => $this->resource->converted,
            'middle' => $this->resource->middle,
            // null: 指定なし / hot: 強制ホット
            'command' => $this->resource->command,
            'note' => $this->resource->note,
            /** @var array{path: list<array{id: int, name: string, name_eng: string}>, brand: array{id: int, name: string, name_eng: string}|null}|null */
            'resolved' => $target === null ? null : $this->resolved($target),
        ];
    }

    /**
     * @return array{path: mixed, brand: array{id: int, name: string, name_eng: string}|null}
     */
    private function resolved(Category|Brand $target): array
    {
        // 銘柄の所属するノードは外部キー(NOT NULL・RESTRICT)で必ずある
        $node = $target instanceof Brand
            ? ($target->category ?? throw new LogicException("銘柄 {$target->id} のノードが無い"))
            : $target;

        return [
            // 根が先頭で、読み替え先のノード(銘柄なら所属するノード)が末尾
            'path' => NodeRefResource::collection(app(CategoryTree::class)->path($node)),
            'brand' => $target instanceof Brand ? [
                'id' => $target->id,
                'name' => $target->name,
                'name_eng' => $target->name_eng,
            ] : null,
        ];
    }
}
