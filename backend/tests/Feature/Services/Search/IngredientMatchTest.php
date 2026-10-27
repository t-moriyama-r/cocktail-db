<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use App\Services\Search\IngredientMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

// IngredientMatch(#162)が、docs/search.md の「新しい木での意味」(#161)のとおりに一致を判定するかを、
// MariaDB の閉包表で確かめる。木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

/**
 * テストに使う木。名前は docs/search.md の例に合わせ、深さ 5 まで伸ばしてある。
 *
 * 洋酒(根)
 * ├ ラム(1)
 * │  ├ ホワイトラム(2)── 銘柄: バカルディ
 * │  │  ├ 熟成ホワイト(3)
 * │  │  │  ├ 樽熟成(4)
 * │  │  │  │  ├ 長期樽熟成(5)
 * │  │  │  │  └ 短期樽熟成(5)
 * │  │  │  └ 瓶熟成(4)
 * │  │  └ 若いホワイト(3)
 * │  └ ダークラム(2)
 * └ ジン(1)
 * ソフトドリンク(根。soft_drink)
 * ├ コーラ(1)
 * └ ソーダ(1)
 * その他(根。other)
 * ├ 砂糖(1)
 * └ 果物(1)
 *    └ ライム(2)
 *
 * @return array<string, Category>
 */
function ingredientMatchTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, ?string $role = null): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'role' => $role])->getAttributes(),
    );

    $n = [];
    $n['洋酒'] = $add(null, '洋酒');
    $n['ラム'] = $add($n['洋酒'], 'ラム');
    $n['ホワイトラム'] = $add($n['ラム'], 'ホワイトラム');
    $n['熟成ホワイト'] = $add($n['ホワイトラム'], '熟成ホワイト');
    $n['樽熟成'] = $add($n['熟成ホワイト'], '樽熟成');
    $n['長期樽熟成'] = $add($n['樽熟成'], '長期樽熟成');
    $n['短期樽熟成'] = $add($n['樽熟成'], '短期樽熟成');
    $n['瓶熟成'] = $add($n['熟成ホワイト'], '瓶熟成');
    $n['若いホワイト'] = $add($n['ホワイトラム'], '若いホワイト');
    $n['ダークラム'] = $add($n['ラム'], 'ダークラム');
    $n['ジン'] = $add($n['洋酒'], 'ジン');
    $n['ソフトドリンク'] = $add(null, 'ソフトドリンク', 'soft_drink');
    $n['コーラ'] = $add($n['ソフトドリンク'], 'コーラ');
    $n['ソーダ'] = $add($n['ソフトドリンク'], 'ソーダ');
    $n['その他'] = $add(null, 'その他', 'other');
    $n['砂糖'] = $add($n['その他'], '砂糖');
    $n['果物'] = $add($n['その他'], '果物');
    $n['ライム'] = $add($n['果物'], 'ライム');

    return $n;
}

/**
 * 材料を並べてカクテルを作る。材料は [ノード, 任意か] か、銘柄つきなら [銘柄, 任意か]。
 *
 * @param  list<array{Category|Brand, bool}>  $ingredients
 */
function ingredientMatchCocktail(string $name, array $ingredients): Cocktail
{
    $cocktail = Cocktail::factory()->create(['name' => $name]);
    foreach ($ingredients as $position => [$item, $isOptional]) {
        $factory = CocktailIngredient::factory();
        $factory = $item instanceof Brand
            ? $factory->forBrand($item)
            : $factory->state(['category_id' => $item->id]);
        $factory->create([
            'cocktail_id' => $cocktail->id,
            'position' => $position + 1,
            'is_optional' => $isOptional,
        ]);
    }

    return $cocktail;
}

/**
 * 名前で引いたノードの ID。
 *
 * @param  array<string, Category>  $nodes
 * @return list<int>
 */
function ingredientMatchIds(array $nodes, string ...$names): array
{
    return array_values(array_map(fn (string $name): int => $nodes[$name]->id, $names));
}

/**
 * 一致するノードの集合を、名前の昇順で返す。
 *
 * @param  list<int>  $selected
 * @return list<string>
 */
function ingredientMatchMatchedNames(array $selected): array
{
    $names = Category::query()
        ->whereIn('id', app(IngredientMatch::class)->matchingNodeIds($selected))
        ->get()
        ->map(fn (Category $node): string => $node->name)
        ->all();

    return ingredientMatchSorted($names);
}

/**
 * 絞り込んだカクテルの名前を、昇順で返す。
 *
 * @param  callable(Builder<Cocktail>): Builder<Cocktail>  $filter
 * @return list<string>
 */
function ingredientMatchCocktailNames(callable $filter): array
{
    $names = $filter(Cocktail::query())
        ->get()
        ->map(fn (Cocktail $cocktail): string => $cocktail->name)
        ->all();

    return ingredientMatchSorted($names);
}

/**
 * 名前を昇順に並べ、0 からの連番の配列にする。
 *
 * @param  array<int, string>  $names
 * @return list<string>
 */
function ingredientMatchSorted(array $names): array
{
    sort($names);

    return $names;
}

describe('一致するノードの集合', function () {
    beforeEach(function () {
        $this->n = ingredientMatchTree();
    });

    it('TC-162-01: 根の直下のノードを選ぶと、自分と配下が一致し、根・兄弟は一致しない', function () {
        expect(ingredientMatchMatchedNames(ingredientMatchIds($this->n, 'ラム')))
            ->toBe(ingredientMatchSorted([
                'ラム', 'ホワイトラム', '熟成ホワイト', '樽熟成', '長期樽熟成', '短期樽熟成', '瓶熟成', '若いホワイト', 'ダークラム',
            ]));
    });

    it('TC-162-02: 中間のノードを選ぶと、自分と配下と、根を除く祖先が一致し、祖先の兄弟の部分木は一致しない', function () {
        expect(ingredientMatchMatchedNames(ingredientMatchIds($this->n, '熟成ホワイト')))
            ->toBe(ingredientMatchSorted([
                '熟成ホワイト', '樽熟成', '長期樽熟成', '短期樽熟成', '瓶熟成', 'ホワイトラム', 'ラム',
            ]));
    });

    it('TC-162-03: 葉(深さ 5)を選ぶと、自分と根を除く祖先が一致し、兄弟は一致しない', function () {
        expect(ingredientMatchMatchedNames(ingredientMatchIds($this->n, '長期樽熟成')))
            ->toBe(ingredientMatchSorted([
                '長期樽熟成', '樽熟成', '熟成ホワイト', 'ホワイトラム', 'ラム',
            ]));
    });

    it('TC-162-04: 銘柄の所属ノードを選ぶと、ほかのノードと同じ規則で一致し、銘柄つきの材料も所属ノードで一致する', function () {
        $bacardi = Brand::factory()->create(['category_id' => $this->n['ホワイトラム']->id]);
        ingredientMatchCocktail('ダイキリ', [[$bacardi, false], [$this->n['ライム'], false]]);
        ingredientMatchCocktail('ラム・コーク', [[$this->n['ダークラム'], false], [$this->n['コーラ'], false]]);
        $selected = ingredientMatchIds($this->n, 'ホワイトラム');

        expect(ingredientMatchMatchedNames($selected))
            ->toBe(ingredientMatchSorted([
                'ホワイトラム', '熟成ホワイト', '樽熟成', '長期樽熟成', '短期樽熟成', '瓶熟成', '若いホワイト', 'ラム',
            ]))
            ->and(ingredientMatchCocktailNames(fn (Builder $q) => app(IngredientMatch::class)->whereUsesAny($q, $selected)))
            ->toBe(['ダイキリ']);
    });

    it('TC-162-05: 根を選ぶと、根の部分木のすべてが一致する', function () {
        expect(ingredientMatchMatchedNames(ingredientMatchIds($this->n, 'その他')))
            ->toBe(ingredientMatchSorted(['その他', '砂糖', '果物', 'ライム']));
    });

    it('TC-162-06: 複数のノードを選ぶと、それぞれの一致の和になる', function () {
        expect(ingredientMatchMatchedNames(ingredientMatchIds($this->n, 'ダークラム', 'コーラ')))
            ->toBe(ingredientMatchSorted(['ダークラム', 'ラム', 'コーラ']));
    });

    it('TC-162-07: 1 つも選ばないと、どのノードも一致しない', function () {
        expect(ingredientMatchMatchedNames([]))->toBe([]);
    });
});

describe('材料で絞り込む(or・and)', function () {
    beforeEach(function () {
        $this->n = ingredientMatchTree();
        $n = $this->n;
        ingredientMatchCocktail('キューバ・リブレ', [[$n['ラム'], false], [$n['コーラ'], false], [$n['ライム'], false]]);
        ingredientMatchCocktail('モヒート', [[$n['ホワイトラム'], false], [$n['砂糖'], false], [$n['ソーダ'], false]]);
        ingredientMatchCocktail('ラム・コーク', [[$n['ダークラム'], false], [$n['コーラ'], false]]);
        ingredientMatchCocktail('マイタイ', [[$n['ホワイトラム'], false], [$n['ダークラム'], false]]);
        ingredientMatchCocktail('ジン・トニック', [[$n['ジン'], false], [$n['ソーダ'], false], [$n['ライム'], true]]);
        $this->match = app(IngredientMatch::class);
    });

    it('TC-162-08: or で、選んだノードの配下・祖先(大まかな指定)の材料を持つカクテルが当たる', function () {
        $selected = ingredientMatchIds($this->n, 'ホワイトラム');

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesAny($q, $selected)))
            ->toBe(ingredientMatchSorted(['キューバ・リブレ', 'モヒート', 'マイタイ']));
    });

    it('TC-162-09: or で、任意の材料も一致の対象になる', function () {
        $selected = ingredientMatchIds($this->n, 'ライム');

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesAny($q, $selected)))
            ->toBe(ingredientMatchSorted(['キューバ・リブレ', 'ジン・トニック']));
    });

    it('TC-162-10: and で、条件ごとに一致する材料を持つカクテルだけが当たる', function () {
        $conditions = [ingredientMatchIds($this->n, 'ホワイトラム'), ingredientMatchIds($this->n, 'コーラ')];

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesEach($q, $conditions)))
            ->toBe(['キューバ・リブレ']);
    });

    it('TC-162-11: and で、祖先と子孫を別の条件にしても、子孫だけの条件と同じ結果になる', function () {
        $both = [ingredientMatchIds($this->n, 'ラム'), ingredientMatchIds($this->n, 'ホワイトラム')];
        $deeperOnly = [ingredientMatchIds($this->n, 'ホワイトラム')];

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesEach($q, $both)))
            ->toBe(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesEach($q, $deeperOnly)))
            ->toBe(ingredientMatchSorted(['キューバ・リブレ', 'モヒート', 'マイタイ']));
    });

    it('TC-162-12: and で、兄弟を別々の条件にするとすべてが要り、1 つの条件にまとめるとどれか 1 つでよい', function () {
        $separate = [ingredientMatchIds($this->n, 'ホワイトラム'), ingredientMatchIds($this->n, 'ダークラム')];
        $grouped = [ingredientMatchIds($this->n, 'ホワイトラム', 'ダークラム')];

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesEach($q, $separate)))
            ->toBe(ingredientMatchSorted(['キューバ・リブレ', 'マイタイ']))
            ->and(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesEach($q, $grouped)))
            ->toBe(ingredientMatchSorted(['キューバ・リブレ', 'モヒート', 'ラム・コーク', 'マイタイ']));
    });

    it('TC-162-13: and で、どれか 1 つの条件に一致する材料が無いカクテルは当たらない', function () {
        $conditions = [ingredientMatchIds($this->n, 'ジン'), ingredientMatchIds($this->n, 'コーラ')];

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereUsesEach($q, $conditions)))
            ->toBe([]);
    });
});

describe('手持ちの材料で作れるカクテル(having)', function () {
    beforeEach(function () {
        $this->n = ingredientMatchTree();
        $n = $this->n;
        ingredientMatchCocktail('すべてある', [[$n['ホワイトラム'], false], [$n['コーラ'], false]]);
        ingredientMatchCocktail('任意だけ足りない', [[$n['ホワイトラム'], false], [$n['ジン'], true]]);
        ingredientMatchCocktail('その他だけ足りない', [[$n['ホワイトラム'], false], [$n['砂糖'], false]]);
        ingredientMatchCocktail('1 つ足りない', [[$n['ホワイトラム'], false], [$n['ジン'], false]]);
        ingredientMatchCocktail('深いその他だけ足りない', [[$n['ホワイトラム'], false], [$n['ライム'], false]]);
        ingredientMatchCocktail('ソフトドリンクが足りない', [[$n['ホワイトラム'], false], [$n['ソーダ'], false]]);
        ingredientMatchCocktail('大まかな指定のラム', [[$n['ラム'], false], [$n['コーラ'], false]]);
        ingredientMatchCocktail('兄弟のダークラム', [[$n['ダークラム'], false], [$n['コーラ'], false]]);
        $this->match = app(IngredientMatch::class);
        $this->have = ingredientMatchIds($n, 'ホワイトラム', 'コーラ');
    });

    it('TC-162-14: 任意でない材料がすべて一致するカクテルは当たる', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->toContain('すべてある');
    });

    it('TC-162-15: 任意の材料だけ足りないカクテルは当たる', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->toContain('任意だけ足りない');
    });

    it('TC-162-16: 「その他」の材料だけ足りないカクテルは当たる', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->toContain('その他だけ足りない');
    });

    it('TC-162-17: 任意でなく「その他」でもない材料が 1 つ足りないカクテルは当たらない', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->not->toContain('1 つ足りない');
    });

    it('TC-162-18: 「その他」の根の深いところにある材料も、持っていなくてよい', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->toContain('深いその他だけ足りない');
    });

    it('TC-162-19: ソフトドリンクは持っている必要がある', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->not->toContain('ソフトドリンクが足りない');
    });

    it('TC-162-20: 大まかな指定(祖先)の材料は作れる扱いになり、兄弟のノードの材料は作れない扱いになる', function () {
        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, $this->have)))
            ->toBe(ingredientMatchSorted([
                'すべてある', '任意だけ足りない', 'その他だけ足りない', '深いその他だけ足りない', '大まかな指定のラム',
            ]));
    });

    it('TC-162-21: 1 つも選ばないと、任意か「その他」の材料だけのカクテルだけが当たる', function () {
        ingredientMatchCocktail('その他と任意だけ', [[$this->n['砂糖'], false], [$this->n['ジン'], true]]);

        expect(ingredientMatchCocktailNames(fn (Builder $q) => $this->match->whereMakeableWith($q, [])))
            ->toBe(['その他と任意だけ']);
    });
});
