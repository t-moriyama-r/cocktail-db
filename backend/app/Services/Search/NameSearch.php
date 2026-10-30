<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 名前の横断検索(GET /api/search。#172)。カクテル・ノード・銘柄を、名前の部分一致で種類ごとに探す。
 *
 * 旧の SearchController@fromName(Search::generate_search_array・Search::find_Jap_or_Eng)と同じ一致にする。
 * 材料名からカクテルを探す欄(ingredient_matches。旧のインテリジェンスサーチ CocktailFactory::intelligenceSearch。#173)も返す。
 * カクテルは、別名(cocktail_aliases。#82。旧に無い)でも探す。
 * 旧と変えたところは docs/decisions/name-search.md の「旧との差分」。
 */
class NameSearch
{
    public function __construct(private readonly IngredientMatch $match) {}

    /**
     * $query に名前が一致するカクテル(下書きを除く)・ノード(根を除く)・銘柄と、$query の語を材料に使うカクテルを、
     * 種類ごとに ID の順で返す。
     *
     * カクテルは、名前か別名のどちらかが一致したもの。名前では当たらず別名で当たったカクテルには、当たった別名の名前を
     * 属性 matched_alias に入れる(当たった別名が 2 つ以上なら、先に登録した方。名前で当たったカクテルは null)。
     *
     * @return array{cocktails: Collection<int, Cocktail>, nodes: Collection<int, Category>, brands: Collection<int, Brand>, ingredient_matches: Collection<int, Cocktail>}
     */
    public function search(string $query): array
    {
        $words = self::words($query);
        // 旧と同じく、空白で分ける前の入力全体で英数字だけかを見る(空白を含めば英名は探さない)
        $columns = ctype_alnum($query) ? ['name_eng', 'name_search'] : ['name_search'];

        return [
            'cocktails' => $this->cocktails($words, $columns),
            // 根(旧の種別)は旧の検索に入っていなかったので除く。旧のカテゴリとミドルは、どちらも根の下のノード
            'nodes' => $this->matching(Category::query()->has('ancestors'), $words, $columns)
                ->with('parent')->get(),
            'brands' => $this->matching(Brand::query(), $words, $columns)
                ->with('category')->get(),
            // 旧と同じく、英数字だけの入力なら英名だけ、それ以外は検索用の名前だけで、ノード・銘柄の完全一致を探す
            'ingredient_matches' => $this->ingredientMatches($words, ctype_alnum($query) ? 'name_eng' : 'name_search'),
        ];
    }

    /**
     * 名前か別名が一致するカクテル(下書きを除く)を ID の順で返す。別名は英名を持たないので、検索用の名前だけで探す。
     *
     * @param  list<string>  $words
     * @param  list<string>  $columns
     * @return Collection<int, Cocktail>
     */
    private function cocktails(array $words, array $columns): Collection
    {
        $byName = $this->matching(Cocktail::query()->published(), $words, $columns)
            ->with(['baseCategory', 'method'])->get();

        // 名前で当たらなかったカクテルの別名だけを見る。別名の ID の順なので、先に登録した別名が先に来る
        $aliasNames = [];
        $aliases = $this->matching(
            CocktailAlias::query()
                ->whereHas('cocktail', fn (Builder $cocktail) => $cocktail->published())
                ->whereNotIn('cocktail_id', $byName->modelKeys()),
            $words,
            ['name_search'],
        )->get(['cocktail_id', 'name']);
        foreach ($aliases as $alias) {
            $aliasNames[$alias->cocktail_id] ??= $alias->name;
        }

        $byAlias = Cocktail::query()->whereKey(array_keys($aliasNames))->with(['baseCategory', 'method'])->get();
        foreach ($byAlias as $cocktail) {
            $cocktail->setAttribute('matched_alias', $aliasNames[$cocktail->id]);
        }

        return $byName->concat($byAlias)->sortBy('id')->values();
    }

    /**
     * 語ごとに、名前が語そのものか、語に「リキュール」(英名なら liqueur)を付けたものと完全一致するノード(根を除く)・
     * 銘柄を探し、それを材料に使うカクテル(下書きを除く)を ID の順で返す。語の間は AND。
     *
     * ノードで当たったら、そのノードと一致する材料(IngredientMatch。#161・#162)を、銘柄で当たったらその銘柄を指定した
     * 材料を持つカクテル。1 つの語でノードと銘柄の両方に当たったら、どちらかを使えばよい(旧と同じ OR)。
     * どれかの語でノードにも銘柄にも当たらなければ、ほかの語によらず 0 件(旧と同じ)。
     *
     * @param  list<string>  $words
     * @return Collection<int, Cocktail>
     */
    private function ingredientMatches(array $words, string $column): Collection
    {
        $cocktails = Cocktail::query()->published();

        foreach ($words as $word) {
            // 旧は英名にも空白を挟まずに付けていた(melonliqueur)。旧と同じにする
            $names = [$word, $word.($column === 'name_eng' ? 'liqueur' : 'リキュール')];
            /** @var list<int> $nodeIds */
            $nodeIds = Category::query()->has('ancestors')->whereIn($column, $names)->pluck('id')->all();
            /** @var list<int> $brandIds */
            $brandIds = Brand::query()->whereIn($column, $names)->pluck('id')->all();

            if ($nodeIds === [] && $brandIds === []) {
                return new Collection;
            }

            $cocktails->whereHas('ingredients', fn (Builder $ingredients) => $ingredients
                ->where(function (Builder $any) use ($nodeIds, $brandIds) {
                    if ($nodeIds !== []) {
                        $any->orWhereIn('category_id', $this->match->matchingNodeIds($nodeIds));
                    }
                    if ($brandIds !== []) {
                        $any->orWhereIn('brand_id', $brandIds);
                    }
                }));
        }

        return $cocktails->with(['baseCategory', 'method'])->orderBy('id')->get();
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
