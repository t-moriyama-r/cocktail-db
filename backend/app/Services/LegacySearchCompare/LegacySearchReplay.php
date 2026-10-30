<?php

namespace App\Services\LegacySearchCompare;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧の条件検索(旧ソース laravel13 の CocktailFactory::cocktailSearch)を、旧 DB(接続 legacy)に対して同じ SQL で再現する(#169)。
 * 読むだけで、何も書き換えない。
 *
 * 旧の SQL の意味は docs/search.md の「旧の条件」。旧は Laravel のクエリビルダで組んでいて、空の配列の whereIn は常に偽、
 * whereNotIn は常に真になる。ここでも同じクエリビルダで組むので、空の配列の扱いも旧と同じになる。
 *
 * search に ExpectedDiff を渡すと、その差分(docs/search-expected-diffs.md の節)の「新」の側に合わせて旧の検索を変える。
 * 何も渡さなければ旧のまま。
 */
final class LegacySearchReplay
{
    /** 旧の種別「その他」の ID(docs/search.md の「旧の仕組み」) */
    private const OTHER_KIND = 5;

    /** 旧の、かんたん検索で出す材料の数の上限 */
    private const EASY_MAX_RECIPE_COUNT = 3;

    /** @var array<int, array{method: int, is_draft: bool, recipe_count: int}>|null 旧のカクテル。ID の昇順 */
    private ?array $cocktails = null;

    /** @var array<int, list<int>>|null 旧のカテゴリの ID => 子のミドルの ID(昇順) */
    private ?array $middlesByCategory = null;

    /** @var array<int, int>|null 旧のカクテルの ID => 決まったこと 1 の定義で数え直した材料の数 */
    private ?array $recountedRecipeCounts = null;

    private function legacy(): Connection
    {
        return DB::connection('legacy');
    }

    /**
     * SearchCase の選び方を、旧 DB の ID に直す。
     */
    public function resolve(SearchCase $case): LegacyCondition
    {
        $categories = array_is_list($case->categories) && $case->categories !== []
            ? self::intList($case->categories)
            : $this->selectCategories($case->categories);

        $middles = match ($case->middles) {
            'children' => $this->childMiddles($categories, firstOnly: false),
            'first_child' => $this->childMiddles($categories, firstOnly: true),
            'none' => [],
            'any' => [LegacyCondition::ANY_MIDDLE],
            default => is_array($case->middles) ? $case->middles : throw new UnexpectedValueException("middles の選び方が分からない: {$case->middles}"),
        };

        $methods = $case->methods ?? self::intList($this->legacy()->table('m_method')->orderBy('id')->pluck('id')->all());

        return new LegacyCondition($case->mode, $categories, $middles, $methods, $case->easy);
    }

    /**
     * 旧の検索で当たるカクテルの ID(旧の ID。昇順)。
     *
     * @param  list<ExpectedDiff>  $diffs  旧の検索に当てる差分
     * @return list<int>
     */
    public function search(LegacyCondition $condition, array $diffs = []): array
    {
        $ids = match ($condition->mode) {
            SearchCase::OR => $this->searchOr($condition, $diffs),
            SearchCase::AND => $this->searchAnd($condition, $diffs),
            SearchCase::HAVING => $this->searchHaving($condition, $diffs),
            default => throw new UnexpectedValueException("方式が分からない: {$condition->mode}"),
        };

        return $this->narrow($ids, $condition, $diffs);
    }

    /**
     * OR 検索: 「親カテゴリが含まれ」かつ「ミドルカテゴリが含まれるか、null である」材料の行を持つカクテル。
     *
     * @param  list<ExpectedDiff>  $diffs
     * @return array<int, true>
     */
    private function searchOr(LegacyCondition $condition, array $diffs): array
    {
        $recipes = $this->recipes()->whereIn($this->category($diffs), $condition->categories);
        if (! $condition->anyMiddle()) {
            $middles = $this->middles($condition, $diffs);
            $recipes->where(fn (Builder $query) => $query
                ->whereIn('r.middle_category', $middles)
                ->orWhereNull('r.middle_category'));
        }

        return self::idSet($recipes->pluck('r.cocktail_id')->all());
    }

    /**
     * AND 検索: カテゴリごとに、そのカテゴリで「ミドルが含まれるか null」の材料の行を持つカクテルを絞り込んでいく。
     * ミドルは各カテゴリの一致を絞る条件としてだけ効く(docs/search.md の「searchMethod=and」)。
     *
     * @param  list<ExpectedDiff>  $diffs
     * @return array<int, true>
     */
    private function searchAnd(LegacyCondition $condition, array $diffs): array
    {
        $middles = $this->middles($condition, $diffs);
        $ids = null;
        foreach ($condition->categories as $category) {
            $recipes = $this->recipes()
                ->where($this->category($diffs), $category)
                ->where(fn (Builder $query) => $query
                    ->whereIn('r.middle_category', $middles)
                    ->orWhereNull('r.middle_category'));
            $found = self::idSet($recipes->pluck('r.cocktail_id')->all());
            $ids = $ids === null ? $found : array_intersect_key($ids, $found);
        }

        return $ids ?? [];
    }

    /**
     * あるもの検索: 任意でない材料の行のうち、「カテゴリが含まれず、種別がその他でない」か「ミドルが含まれない」行を持つ
     * カクテルを除く。残すのは ID が 1 からカクテルの件数(下書きを含む)までのもの。
     *
     * @param  list<ExpectedDiff>  $diffs
     * @return array<int, true>
     */
    private function searchHaving(LegacyCondition $condition, array $diffs): array
    {
        $category = $this->category($diffs);
        $middles = $this->middles($condition, $diffs);
        $otherAlsoForMiddle = in_array(ExpectedDiff::HavingOtherWithMiddle, $diffs, true);
        $emptyMiddleNeverMatches = in_array(ExpectedDiff::HavingEmptyMiddle, $diffs, true);

        $lacking = $this->recipes()
            ->leftJoin('m_category as k', 'k.id', '=', $category)
            ->where('r.is_optional', 0)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $byCategory) => $byCategory
                    ->whereNotIn($category, $condition->categories)
                    // 旧は whereHas('base_r', kind <> 5)。カテゴリの行が無ければ当たらない
                    ->where('k.kind', '<>', self::OTHER_KIND))
                ->orWhere(function (Builder $byMiddle) use ($middles, $otherAlsoForMiddle, $emptyMiddleNeverMatches) {
                    if ($middles === [] && $emptyMiddleNeverMatches) {
                        $byMiddle->whereRaw('1 = 0');

                        return;
                    }
                    // 空の配列なら、旧と同じく常に真になる
                    $byMiddle->whereNotIn('r.middle_category', $middles);
                    if ($otherAlsoForMiddle) {
                        $byMiddle->where('k.kind', '<>', self::OTHER_KIND);
                    }
                }));
        $lackingIds = self::idSet($lacking->pluck('r.cocktail_id')->all());

        $candidates = in_array(ExpectedDiff::HavingIdBeyondCount, $diffs, true)
            ? array_keys($this->cocktails())
            : ($this->cocktails() === [] ? [] : range(1, count($this->cocktails())));

        return array_diff_key(array_fill_keys($candidates, true), $lackingIds);
    }

    /**
     * 3 つに共通の絞り込み: 旧のカクテルがあり、技法が Method にあり、下書きでなく、かんたん検索なら材料の数が 3 以下。
     *
     * @param  array<int, true>  $ids
     * @param  list<ExpectedDiff>  $diffs
     * @return list<int>
     */
    private function narrow(array $ids, LegacyCondition $condition, array $diffs): array
    {
        $methods = array_fill_keys($condition->methods, true);
        $recount = $condition->easy && in_array(ExpectedDiff::EasyRecipeCount, $diffs, true);

        $found = [];
        foreach ($this->cocktails() as $id => $cocktail) {
            if (! isset($ids[$id]) || ! isset($methods[$cocktail['method']]) || $cocktail['is_draft']) {
                continue;
            }
            $recipeCount = $recount ? ($this->recountedRecipeCounts()[$id] ?? 0) : $cocktail['recipe_count'];
            if ($condition->easy && $recipeCount > self::EASY_MAX_RECIPE_COUNT) {
                continue;
            }
            $found[] = $id;
        }

        return $found;
    }

    /**
     * 材料の行のカクテルの ID(重なりなし)。ミドルの親を引けるよう、ミドルを外部結合しておく。
     */
    private function recipes(): Builder
    {
        return $this->legacy()->table('t_recipies as r')
            ->leftJoin('m_middle as mm', 'mm.id', '=', 'r.middle_category')
            ->select('r.cocktail_id')
            ->distinct();
    }

    /**
     * 材料の行のカテゴリ。旧は行のカテゴリの列を見る。#28 の差分を当てると、ミドルのある行はミドルの親を見る
     * (移行がミドルの親に合わせた。docs/migration-anomalies.md の 3)。
     *
     * @param  list<ExpectedDiff>  $diffs
     */
    private function category(array $diffs): Expression|string
    {
        return in_array(ExpectedDiff::MiddleParentMismatch, $diffs, true)
            ? $this->legacy()->raw('COALESCE(mm.parent, r.category)')
            : 'r.category';
    }

    /**
     * 検索に使う Middle。カテゴリを選び、その子のミドルを 1 つも選ばない条件の差分を当てると、そのカテゴリの子の
     * ミドルをすべて足す(新はそのカテゴリを 1 つ選んだ条件に直り、配下も一致する)。
     *
     * @param  list<ExpectedDiff>  $diffs
     * @return list<int>
     */
    private function middles(LegacyCondition $condition, array $diffs): array
    {
        $widen = $condition->mode === SearchCase::HAVING
            ? in_array(ExpectedDiff::HavingCategoryWithoutMiddles, $diffs, true)
            : in_array(ExpectedDiff::CategoryWithoutMiddles, $diffs, true);
        if (! $widen) {
            return $condition->middles;
        }

        $selected = array_fill_keys($condition->middles, true);
        $middles = $condition->middles;
        foreach (array_unique($condition->categories) as $category) {
            $children = $this->middlesByCategory()[$category] ?? [];
            if ($children !== [] && array_intersect_key(array_fill_keys($children, true), $selected) === []) {
                array_push($middles, ...$children);
            }
        }

        return $middles;
    }

    /**
     * @param  array<array-key, mixed>  $selector  SearchCase の categories(選び方のオブジェクト)
     * @return list<int>
     */
    private function selectCategories(array $selector): array
    {
        $query = $this->legacy()->table('m_category as c')->select('c.id')->orderBy('c.id');
        if (isset($selector['kind'])) {
            $query->whereIn('c.kind', self::intList($selector['kind']));
        }
        if (isset($selector['not_kind'])) {
            $query->whereNotIn('c.kind', self::intList($selector['not_kind']));
        }
        if (isset($selector['has_middles'])) {
            $hasMiddles = fn (Builder $sub) => $sub->select($sub->raw(1))->from('m_middle as m')->whereColumn('m.parent', 'c.id');
            $selector['has_middles'] === true ? $query->whereExists($hasMiddles) : $query->whereNotExists($hasMiddles);
        }
        if (($selector['optional'] ?? false) === true) {
            $query->whereExists(fn (Builder $sub) => $sub->select($sub->raw(1))
                ->from('t_recipies as r')
                ->join('t_cocktails as t', 't.id', '=', 'r.cocktail_id')
                ->whereColumn('r.category', 'c.id')
                ->where('r.is_optional', 1)
                ->where('t.is_draft', 0));
        }
        if (isset($selector['limit'])) {
            $limit = $selector['limit'];
            $query->limit(is_int($limit) ? $limit : throw new UnexpectedValueException('categories の limit は整数'));
        }

        return self::intList($query->pluck('c.id')->all());
    }

    /**
     * @param  list<int>  $categories
     * @return list<int>
     */
    private function childMiddles(array $categories, bool $firstOnly): array
    {
        $middles = [];
        foreach (array_unique($categories) as $category) {
            $children = $this->middlesByCategory()[$category] ?? [];
            array_push($middles, ...($firstOnly ? array_slice($children, 0, 1) : $children));
        }

        return $middles;
    }

    /**
     * @return array<int, list<int>>
     */
    private function middlesByCategory(): array
    {
        if ($this->middlesByCategory === null) {
            $this->middlesByCategory = [];
            foreach ($this->legacy()->table('m_middle')->orderBy('id')->get(['id', 'parent']) as $row) {
                $this->middlesByCategory[self::int($row->parent)][] = self::int($row->id);
            }
        }

        return $this->middlesByCategory;
    }

    /**
     * @return array<int, array{method: int, is_draft: bool, recipe_count: int}>
     */
    private function cocktails(): array
    {
        if ($this->cocktails === null) {
            $this->cocktails = [];
            foreach ($this->legacy()->table('t_cocktails')->orderBy('id')->get(['id', 'method', 'is_draft', 'recipe_count']) as $row) {
                $this->cocktails[self::int($row->id)] = [
                    'method' => self::int($row->method),
                    'is_draft' => self::int($row->is_draft) !== 0,
                    'recipe_count' => self::int($row->recipe_count),
                ];
            }
        }

        return $this->cocktails;
    }

    /**
     * 決まったこと 1(docs/decisions/recipe-count.md)の定義で、旧の材料の行から材料の数を数え直す。任意でなく、種別が
     * 「その他」でない材料の行を数える。移行と同じく、ミドルのある行はミドルの親の種別で見て
     * (docs/migration-anomalies.md の 3)、材料・銘柄・量まで同じ行は 1 行と数える(同じ 2)。
     *
     * @return array<int, int>
     */
    private function recountedRecipeCounts(): array
    {
        if ($this->recountedRecipeCounts === null) {
            $rows = $this->legacy()->table('t_recipies as r')
                ->leftJoin('m_middle as mm', 'mm.id', '=', 'r.middle_category')
                ->join('m_category as k', 'k.id', '=', $this->legacy()->raw('COALESCE(mm.parent, r.category)'))
                ->where('r.is_optional', 0)
                ->where('k.kind', '<>', self::OTHER_KIND)
                ->distinct()
                ->select('r.cocktail_id', 'k.id as category', 'r.middle_category', 'r.brand', 'r.amount');
            $counts = $this->legacy()->query()->fromSub($rows, 'd')
                ->select('d.cocktail_id', $this->legacy()->raw('COUNT(*) as n'))
                ->groupBy('d.cocktail_id')
                ->get();

            $this->recountedRecipeCounts = [];
            foreach ($counts as $row) {
                $this->recountedRecipeCounts[self::int($row->cocktail_id)] = self::int($row->n);
            }
        }

        return $this->recountedRecipeCounts;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<int, true>
     */
    private static function idSet(array $values): array
    {
        return array_fill_keys(self::intList($values), true);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $values): array
    {
        if (! is_array($values)) {
            throw new UnexpectedValueException('ID の並びでない');
        }

        return array_values(array_map(self::int(...), $values));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new UnexpectedValueException('旧 DB の ID が数でない');
    }
}
