<?php

namespace App\Services\LegacySearchCompare;

use App\Models\Cocktail;
use App\Models\LegacyIdMap;
use App\Services\LegacySearchCondition;
use App\Services\Search\CocktailSearch;
use Illuminate\Database\Eloquent\Builder;
use UnexpectedValueException;

/**
 * 旧の条件検索と新しい条件検索に同じ条件を流し、当たったカクテルの集合を比べる(#169)。読むだけで、何も書き換えない。
 *
 * - 旧: LegacySearchReplay で、旧 DB に旧と同じ SQL を流す
 * - 新: 旧の条件を LegacySearchCondition(#290)で新しいノード・技法の ID に直し、CocktailSearch で引く。AND は、
 *   旧のカテゴリごとに 1 つのまとまりにする(docs/search.md の「旧の条件との対応」)
 * - 旧のカクテルの ID は legacy_id_maps で新しい ID に直して比べる
 *
 * 差分のうち、旧の検索に ExpectedDiff(docs/search-expected-diffs.md の節)を当てた結果でも残るものだけを、
 * 説明できない差分にする。説明できた差分は、どの節で説明できたかを数える。
 */
final class SearchComparer
{
    /** @var array<int, int>|null 旧のカクテルの ID => 新しい ID */
    private ?array $newIdByLegacyId = null;

    /** @var array<int, int>|null 新しいカクテルの ID => 旧の ID */
    private ?array $legacyIdByNewId = null;

    public function __construct(
        private readonly LegacySearchReplay $legacy,
        private readonly LegacySearchCondition $converter,
        private readonly CocktailSearch $search,
    ) {}

    public function compare(SearchCase $case): CaseResult
    {
        $condition = $this->legacy->resolve($case);
        if ($condition->categories === []) {
            // 旧は Category を必須にしていて(SearchCocktail)、空では検索できない
            return CaseResult::skipped($case, '選び方に当たるカテゴリが旧 DB に無い(旧は Category が空だと検索できない)');
        }

        $diffs = ExpectedDiff::forMode($case->mode);
        $strict = $this->legacyKeys($this->legacy->search($condition));
        $relaxed = $this->legacyKeys($this->legacy->search($condition, $diffs));
        $new = $this->newKeys($this->newSearch($condition));

        $onlyNew = array_diff_key($new, $relaxed);
        $onlyLegacy = array_diff_key($relaxed, $new);
        $unexplained = [];
        foreach ($onlyLegacy as $label) {
            $unexplained[] = "旧にあって新に無い: {$label}";
        }
        foreach ($onlyNew as $label) {
            $unexplained[] = "新にあって旧に無い: {$label}";
        }

        $explainedKeys = array_diff_key(self::symmetricDiff($strict, $new), $onlyNew, $onlyLegacy);

        return new CaseResult(
            $case,
            count($strict),
            count($new),
            $this->attribute($condition, $strict, $explainedKeys, $diffs),
            $unexplained,
        );
    }

    /**
     * 説明できた差分を、どの節で説明できたかに分けて数える。1 つの節だけを当てて出る差分は、その節で説明できたとする。
     * どの節を 1 つだけ当てても出ず、いくつかを合わせて初めて出るものは、まとめて数える。
     *
     * @param  array<string, string>  $strict
     * @param  array<string, string>  $explainedKeys
     * @param  list<ExpectedDiff>  $diffs
     * @return array<string, int>
     */
    private function attribute(LegacyCondition $condition, array $strict, array $explainedKeys, array $diffs): array
    {
        $counts = [];
        $remaining = $explainedKeys;
        foreach ($diffs as $diff) {
            if ($remaining === []) {
                break;
            }
            $changed = self::symmetricDiff($strict, $this->legacyKeys($this->legacy->search($condition, [$diff])));
            $hit = array_intersect_key($remaining, $changed);
            if ($hit !== []) {
                $counts[$diff->label()] = count($hit);
                $remaining = array_diff_key($remaining, $hit);
            }
        }
        if ($remaining !== []) {
            $counts['いくつかの節を合わせて説明できるもの'] = count($remaining);
        }

        return $counts;
    }

    /**
     * 新しい検索で当たるカクテルの ID。
     *
     * @return list<int>
     */
    private function newSearch(LegacyCondition $condition): array
    {
        $methodIds = $this->converter->convert([], [], $condition->methods)['method_ids'];

        if ($condition->mode === SearchCase::AND) {
            $groups = [];
            foreach (array_values(array_unique($condition->categories)) as $category) {
                $groups[] = $this->converter->convert([$category], $condition->middles, [])['category_ids'];
            }
            $query = $this->search->queryEach($groups, $methodIds, $condition->easy);
        } else {
            $nodeIds = $this->converter->convert($condition->categories, $condition->middles, [])['category_ids'];
            $query = $condition->mode === SearchCase::HAVING
                ? $this->search->queryMakeable($nodeIds, $methodIds, $condition->easy)
                : $this->search->query($nodeIds, $methodIds, $condition->easy);
        }

        return self::ids($query);
    }

    /**
     * 旧のカクテルの ID を、比べるための鍵(新しい ID に直せるものは新しい ID)と出力に出す名前にする。
     *
     * @param  list<int>  $legacyIds
     * @return array<string, string>
     */
    private function legacyKeys(array $legacyIds): array
    {
        $keys = [];
        foreach ($legacyIds as $legacyId) {
            $newId = $this->newIdByLegacyId()[$legacyId] ?? null;
            if ($newId === null) {
                $keys["legacy:{$legacyId}"] = "t_cocktails #{$legacyId}(新に対応が無い)";
            } else {
                $keys["new:{$newId}"] = "cocktails #{$newId}(t_cocktails #{$legacyId})";
            }
        }

        return $keys;
    }

    /**
     * 新しいカクテルの ID を、比べるための鍵と出力に出す名前にする。
     *
     * @param  list<int>  $newIds
     * @return array<string, string>
     */
    private function newKeys(array $newIds): array
    {
        $keys = [];
        foreach ($newIds as $newId) {
            $legacyId = $this->legacyIdByNewId()[$newId] ?? null;
            $keys["new:{$newId}"] = $legacyId === null
                ? "cocktails #{$newId}(旧に対応が無い)"
                : "cocktails #{$newId}(t_cocktails #{$legacyId})";
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $a
     * @param  array<string, string>  $b
     * @return array<string, string>
     */
    private static function symmetricDiff(array $a, array $b): array
    {
        return array_diff_key($a, $b) + array_diff_key($b, $a);
    }

    /**
     * @return array<int, int>
     */
    private function newIdByLegacyId(): array
    {
        return $this->newIdByLegacyId ??= LegacyIdMap::newIdsByLegacyId('t_cocktails', 'cocktails');
    }

    /**
     * @return array<int, int>
     */
    private function legacyIdByNewId(): array
    {
        return $this->legacyIdByNewId ??= array_flip($this->newIdByLegacyId());
    }

    /**
     * @param  Builder<Cocktail>  $query
     * @return list<int>
     */
    private static function ids(Builder $query): array
    {
        return array_values(array_map(
            fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new UnexpectedValueException('カクテルの ID が数でない'),
            $query->pluck('id')->all(),
        ));
    }
}
