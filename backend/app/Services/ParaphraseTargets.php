<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Support\NameNormalizer;

/**
 * 言い換え辞書の読み替え先(converted・middle の名前)を、木のノードか銘柄に解決する(#316)。
 *
 * 旧の DictionaryController@serachFromWords を新しい木に移したもの。旧と同じく名前は name_search の形にそろえて比べ、
 * 根(旧の種別)は読み替え先にしない(旧は種別を探していなかった)。旧と変えたところは
 * docs/decisions/paraphrase-target.md の「旧との差分」。
 */
class ParaphraseTargets
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * 読み替え先。解決できなければ null。
     *
     * middle が無ければ、converted を、根のすぐ下のノード(旧のカテゴリ)→ 銘柄 → それより深いノード(旧のミドル)の
     * 順に探す(旧と同じ順)。middle があれば、converted のノードの配下から middle の名前のノードを探す。
     */
    public function resolve(string $converted, ?string $middle): Category|Brand|null
    {
        if ($middle !== null) {
            $parent = $this->convertedNode($converted);

            return $parent === null ? null : $this->tree->findByName($middle, within: $parent);
        }

        $node = $this->convertedNode($converted);
        if ($node !== null && $node->ancestors()->count() === 1) {
            return $node;
        }

        return $this->brandNamed($converted) ?? $node;
    }

    /**
     * middle を探す元になる、converted のノード(根は除く)。無ければ null。
     */
    public function convertedNode(string $converted): ?Category
    {
        return $this->tree->findByName($converted, withRoots: false);
    }

    /**
     * 名前が一致する銘柄。同じ名前が複数あれば ID の小さいもの。
     */
    private function brandNamed(string $name): ?Brand
    {
        return Brand::query()
            ->where('name_search', NameNormalizer::normalize($name))
            ->orderBy('id')
            ->first();
    }
}
