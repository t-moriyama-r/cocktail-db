<?php

namespace App\Services;

use App\Enums\CocktailDuplicateMatch;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 管理画面のカクテルの重複チェック(#129。docs/decisions/admin-screens.md の決まったこと 3)。
 *
 * - 同じ名前のカクテル: 旧 DetailEditController@duplicate_get と同じく、英名が同じか、検索用の名前が同じカクテル(下書きも含む。
 *   編集しているカクテルは除く)を集め、編集しているカクテルの保存してある材料に近い順(CocktailDuplicateMatch の順)に並べる
 * - 同じレシピのカクテル: 編集しているカクテルと recipe_signature(#82)が同じカクテル(下書きも含む)。旧には無い
 *
 * どちらも保存は止めない(画面に出すだけ)。材料は、送った入力ではなく、編集しているカクテルの保存してある材料で比べる(旧と同じ)。
 */
class CocktailDuplicates
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * @param  string|null  $name  名前。空なら名前では集めない
     * @param  string|null  $nameEng  英名。空なら英名では集めない
     * @param  int|null  $excludeId  編集しているカクテルの ID。新規なら null
     * @return array{same_name: list<array{cocktail: Cocktail, match: CocktailDuplicateMatch}>, same_recipe: list<array{cocktail: Cocktail, match: CocktailDuplicateMatch}>}
     */
    public function find(?string $name, ?string $nameEng, ?int $excludeId): array
    {
        $mine = $excludeId === null
            ? new Collection
            : CocktailIngredient::query()->where('cocktail_id', $excludeId)->orderBy('position')->get();

        $sameName = $this->sameName($name, $nameEng, $excludeId);
        $sameRecipe = $this->sameRecipe($excludeId);

        // 材料のノードを旧のカテゴリに直すのは、比べるカクテルの材料をまとめて 1 回で引く
        $nodeIds = $this->nodeIds($mine);
        foreach ([...$sameName, ...$sameRecipe] as $cocktail) {
            array_push($nodeIds, ...$this->nodeIds($cocktail->ingredients));
        }
        $tops = $this->tree->topLevelAncestorIds($nodeIds);
        $mineRows = $this->rows($mine, $tops);

        $byMatch = $this->classify($sameName, $mineRows, $tops);
        // 旧は近さごとに分けてから、まとめごとに ID の順に並べた。usort は安定なので、同じ近さの中は ID の順のまま残る
        $order = array_flip(array_map(fn (CocktailDuplicateMatch $m): string => $m->value, CocktailDuplicateMatch::cases()));
        usort($byMatch, fn (array $a, array $b): int => $order[$a['match']->value] <=> $order[$b['match']->value]);

        return ['same_name' => $byMatch, 'same_recipe' => $this->classify($sameRecipe, $mineRows, $tops)];
    }

    /**
     * $cocktails を ID の順のまま、編集しているカクテルの材料 $mineRows との近さを添えた並びにする。
     *
     * @param  Collection<int, Cocktail>  $cocktails
     * @param  list<array{category: int, amount: string}>  $mineRows
     * @param  array<int, int>  $tops
     * @return list<array{cocktail: Cocktail, match: CocktailDuplicateMatch}>
     */
    private function classify(Collection $cocktails, array $mineRows, array $tops): array
    {
        $items = [];
        foreach ($cocktails as $cocktail) {
            $items[] = ['cocktail' => $cocktail, 'match' => self::match($mineRows, $this->rows($cocktail->ingredients, $tops))];
        }

        return $items;
    }

    /**
     * 英名が同じか、検索用の名前が同じカクテルを、ID の順で。
     *
     * @return Collection<int, Cocktail>
     */
    private function sameName(?string $name, ?string $nameEng, ?int $excludeId): Collection
    {
        $name = $name === '' ? null : $name;
        $nameEng = $nameEng === '' ? null : $nameEng;
        if ($name === null && $nameEng === null) {
            return new Collection;
        }

        return $this->withIngredients(Cocktail::query())
            ->where(function (Builder $query) use ($name, $nameEng): void {
                if ($nameEng !== null) {
                    $query->orWhere('name_eng', $nameEng);
                }
                if ($name !== null) {
                    $query->orWhere('name_search', NameNormalizer::normalize($name));
                }
            })
            ->when($excludeId !== null, fn (Builder $query) => $query->whereKeyNot($excludeId))
            ->orderBy('id')
            ->get();
    }

    /**
     * 編集しているカクテルと recipe_signature が同じカクテルを、ID の順で。材料の無いカクテル(null)は、どれとも同じにしない。
     *
     * @return Collection<int, Cocktail>
     */
    private function sameRecipe(?int $excludeId): Collection
    {
        $signature = $excludeId === null ? null : Cocktail::query()->whereKey($excludeId)->value('recipe_signature');
        if (! is_string($signature)) {
            return new Collection;
        }

        return $this->withIngredients(Cocktail::query())
            ->where('recipe_signature', $signature)
            ->whereKeyNot($excludeId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Builder<Cocktail>  $query
     * @return Builder<Cocktail>
     */
    private function withIngredients(Builder $query): Builder
    {
        return $query->with(['ingredients.category', 'ingredients.brand']);
    }

    /**
     * @param  iterable<CocktailIngredient>  $ingredients
     * @return list<int>
     */
    private function nodeIds(iterable $ingredients): array
    {
        $ids = [];
        foreach ($ingredients as $ingredient) {
            $ids[] = $ingredient->category_id;
        }

        return $ids;
    }

    /**
     * 材料の行を、比べる形(旧のカテゴリ・量)にする。根のすぐ下の祖先が引けないノードは、そのノードのまま比べる。
     *
     * @param  iterable<CocktailIngredient>  $ingredients
     * @param  array<int, int>  $tops
     * @return list<array{category: int, amount: string}>
     */
    private function rows(iterable $ingredients, array $tops): array
    {
        $rows = [];
        foreach ($ingredients as $ingredient) {
            $rows[] = ['category' => $tops[$ingredient->category_id] ?? $ingredient->category_id, 'amount' => $ingredient->amount];
        }

        return $rows;
    }

    /**
     * $theirs(並べる側)が、$mine(編集しているカクテル)にどれだけ近いか。旧 duplicate_get の判定をそのまま写したもの。
     *
     * @param  list<array{category: int, amount: string}>  $mine
     * @param  list<array{category: int, amount: string}>  $theirs
     */
    private static function match(array $mine, array $theirs): CocktailDuplicateMatch
    {
        $theirCategories = array_column($theirs, 'category');
        foreach ($mine as $row) {
            if (! in_array($row['category'], $theirCategories, true)) {
                return CocktailDuplicateMatch::Other;
            }
        }

        if (count($mine) !== count($theirs)) {
            return CocktailDuplicateMatch::ContainsAll;
        }

        // 並べる側の材料のどれにも、同じカテゴリで同じ量の材料が編集しているカクテルにあれば、量まで同じ
        foreach ($theirs as $their) {
            $found = false;
            foreach ($mine as $row) {
                if ($row['category'] === $their['category'] && $row['amount'] === $their['amount']) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return CocktailDuplicateMatch::SameIngredients;
            }
        }

        return CocktailDuplicateMatch::SameAmount;
    }
}
