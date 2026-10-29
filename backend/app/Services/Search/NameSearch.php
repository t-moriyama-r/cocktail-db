<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 名前の横断検索(GET /api/search。#172)。カクテル・ノード・銘柄を、名前の部分一致で種類ごとに探す。
 *
 * 旧の SearchController@fromName(Search::generate_search_array・Search::find_Jap_or_Eng)と同じ一致にする。
 * 旧と変えたところは docs/decisions/name-search.md の「旧との差分」。
 */
class NameSearch
{
    /**
     * $query に名前が一致するカクテル(下書きを除く)・ノード(根を除く)・銘柄を、種類ごとに ID の順で返す。
     *
     * @return array{cocktails: Collection<int, Cocktail>, nodes: Collection<int, Category>, brands: Collection<int, Brand>}
     */
    public function search(string $query): array
    {
        $words = self::words($query);
        // 旧と同じく、空白で分ける前の入力全体で英数字だけかを見る(空白を含めば英名は探さない)
        $columns = ctype_alnum($query) ? ['name_eng', 'name_search'] : ['name_search'];

        return [
            'cocktails' => $this->matching(Cocktail::query()->published(), $words, $columns)
                ->with(['baseCategory', 'method'])->get(),
            // 根(旧の種別)は旧の検索に入っていなかったので除く。旧のカテゴリとミドルは、どちらも根の下のノード
            'nodes' => $this->matching(Category::query()->has('ancestors'), $words, $columns)
                ->with('parent')->get(),
            'brands' => $this->matching(Brand::query(), $words, $columns)
                ->with('category')->get(),
        ];
    }

    /**
     * 入力を、半角・全角の空白で語に分け、それぞれを検索用の名前の形(NameNormalizer)にそろえる。空になった語は除く。
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $words = array_map(NameNormalizer::normalize(...), preg_split('/[ 　]/u', $query) ?: []);

        return array_values(array_filter($words, fn (string $word): bool => $word !== ''));
    }

    /**
     * $columns のどれかに、$words のどれかを部分一致で含むものに絞り、ID の順に並べる。
     *
     * 語の % と _ は、ふつうの文字として探す(LIKE の特別な文字にしない。docs/decisions/name-search.md の決まったこと 2)。
     * 語が 1 つも無い入力は、NameSearchRequest が 422 にするので、ここには来ない。
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $words
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function matching(Builder $query, array $words, array $columns): Builder
    {
        return $query
            ->where(function (Builder $match) use ($words, $columns) {
                foreach ($columns as $column) {
                    foreach ($words as $word) {
                        $match->orWhere($match->qualifyColumn($column), 'like', '%'.addcslashes($word, '\\%_').'%');
                    }
                }
            })
            ->orderBy($query->qualifyColumn('id'));
    }
}
