<?php

namespace App\Console\Commands;

use App\Models\Affiliate;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use App\Services\DescriptionMarkup;
use App\Services\RecipeCounter;
use App\Support\DescriptionParser;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * カテゴリ木の外にある、木とつながった値の整合性を調べる。読むだけで、何も書き換えない。
 *
 * 次の決まりは DB の制約では守れず、保存のときの検証と数え直しで守る決まり(docs/schema.md の「材料が銘柄を持つときの
 * ノード」「材料とベースに使ってよいノード」、docs/decisions/recipe-count.md、docs/decisions/strength.md)。
 * 保存の処理の取りこぼしや移行で破れても、検索・材料の数・度数の計算が黙って食い違うだけなので、機械で確かめる。
 * 木そのもの(閉包表)は tree:verify が見る。
 *
 * 説明文(description・カクテルの note)の参照の記法も、指す先があるかと、旧の記法(`@p`・`@m`)が残っていないかを見る
 * (#285。docs/decisions/description-references.md)。指す先の無い参照は、画面では黙って消えるだけなので、機械で見つける。
 * 記法の外に `@`・`＠` が残っていないか(管理画面で保存するときの検査を通るか。#392・#393)も見る。残っていると、管理画面で
 * その行を編集して保存できない。
 *
 * 有名な銘柄を持たないノードは、移行したデータに残りうる(docs/decisions/strength.md の決まったこと 4)ので、
 * 失敗ではなく警告にし、--strict のときだけ失敗に数える。
 */
final class VerifyData extends Command
{
    protected $signature = 'data:verify
        {--strict : 警告(有名な銘柄を持たないノード)も失敗に数える}';

    protected $description = '材料・アフィリエイトのノード、材料の数(recipe_count)、根の使い方、有名な銘柄、説明文の参照と記法が決まりどおりかを調べ、破れていれば一覧を出して失敗する';

    private const INGREDIENT_BRAND_NODE = '材料のノードと銘柄の所属';

    private const AFFILIATE_BRAND_NODE = 'アフィリエイトのノードと銘柄の所属';

    private const RECIPE_COUNT = '材料の数(recipe_count)';

    private const ROOT_INGREDIENT = '材料に根';

    private const ROOT_BASE = 'ベースに根';

    private const FAMOUS_BRAND_SUBTREE = '有名な銘柄が部分木の外';

    private const MISSING_FAMOUS_BRAND = '有名な銘柄が無い';

    private const DESCRIPTION_REFERENCE_TARGET = '説明文の参照の指す先が無い';

    private const DESCRIPTION_LEGACY_REFERENCE = '説明文に旧の記法';

    private const DESCRIPTION_STRAY_AT = '説明文に記法の外の @';

    /**
     * 説明文を持つ表 => [表に出す名前, 説明文の列]。
     *
     * @var array<string, array{string, list<string>}>
     */
    private const DESCRIPTION_COLUMNS = [
        'categories' => ['ノード', ['description']],
        'methods' => ['技法', ['description']],
        'dictionaries' => ['用語', ['description']],
        'brands' => ['銘柄', ['description']],
        'cocktails' => ['カクテル', ['description', 'note']],
    ];

    /**
     * 参照の記法の種類 => [指す先の表, 表に出す名前]。種類は DescriptionParser::REFERENCE_PATTERN の n・c・b・d。
     *
     * @var array<string, array{string, string}>
     */
    private const REFERENCE_TARGETS = [
        'n' => ['categories', 'ノード'],
        'c' => ['cocktails', 'カクテル'],
        'b' => ['brands', '銘柄'],
        'd' => ['dictionaries', '用語'],
    ];

    /**
     * 旧の、カテゴリ(`@p`)・ミドル(`@m`)を旧の ID で指す記法。移行(legacy:import の descriptions)で `@n` に直す。
     */
    private const LEGACY_REFERENCE_PATTERN = '/@[pPmM][1-9][0-9]*@/';

    private const TABLE_HEADERS = ['調べたこと', '対象', '内容'];

    /** @var array<int, string> ノードの ID → 名前 */
    private array $nodeNames = [];

    public function handle(CategoryTree $tree, RecipeCounter $counter): int
    {
        foreach (Category::query()->orderBy('id')->get(['id', 'name']) as $category) {
            $this->nodeNames[$category->id] = $category->name;
        }

        $violations = [
            ...$this->ingredientBrandNodeViolations(),
            ...$this->affiliateBrandNodeViolations(),
            ...$this->recipeCountViolations($counter),
            ...$this->rootIngredientViolations($tree),
            ...$this->rootBaseViolations($tree),
            ...$this->famousBrandSubtreeViolations(),
            ...$this->descriptionReferenceViolations(),
        ];
        $warnings = $this->missingFamousBrandWarnings($tree);
        $warningsFail = $warnings !== [] && $this->option('strict');

        if ($warnings !== []) {
            $this->warn(sprintf('警告(%d 件)%s:', count($warnings), $warningsFail ? '。--strict なので失敗に数える' : ''));
            $this->table(self::TABLE_HEADERS, $warnings);
        }

        if ($violations !== []) {
            $this->error(sprintf('整合性が破れている(%d 件):', count($violations)));
            $this->table(self::TABLE_HEADERS, $violations);
        } elseif ($warnings === []) {
            $this->info('問題なし');
        } elseif (! $warningsFail) {
            $this->info('失敗に数える破れは無い');
        }

        return $violations !== [] || $warningsFail ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 材料が銘柄を持つときは、材料のノードを銘柄の所属ノードと同じにする(#19)。違うと、材料のノードで引く検索と、
     * 銘柄の所属で引く銘柄の詳細で、そのカクテルが出る・出ないが食い違う。
     *
     * @return list<array{string, string, string}>
     */
    private function ingredientBrandNodeViolations(): array
    {
        $rows = CocktailIngredient::query()
            ->toBase()
            ->join('brands', 'brands.id', '=', 'cocktail_ingredients.brand_id')
            ->join('cocktails', 'cocktails.id', '=', 'cocktail_ingredients.cocktail_id')
            ->whereColumn('cocktail_ingredients.category_id', '<>', 'brands.category_id')
            ->orderBy('cocktail_ingredients.cocktail_id')
            ->orderBy('cocktail_ingredients.position')
            ->get([
                'cocktail_ingredients.cocktail_id', 'cocktails.name as cocktail_name', 'cocktail_ingredients.position',
                'cocktail_ingredients.category_id', 'brands.id as brand_id', 'brands.name as brand_name',
                'brands.category_id as brand_category_id',
            ]);

        $violations = [];
        foreach ($rows as $row) {
            $violations[] = [
                self::INGREDIENT_BRAND_NODE,
                $this->ingredientLabel($row->cocktail_id, $row->cocktail_name, $row->position),
                sprintf(
                    '材料のノードは %s、銘柄 #%s %s の所属は %s',
                    $this->nodeLabel($row->category_id),
                    self::str($row->brand_id),
                    self::str($row->brand_name),
                    $this->nodeLabel($row->brand_category_id),
                ),
            ];
        }

        return $violations;
    }

    /**
     * 銘柄の商品の行も、ノードを銘柄の所属ノードと同じにする(#19)。違うと、ノードの詳細に別の系統の商品が出る。
     *
     * @return list<array{string, string, string}>
     */
    private function affiliateBrandNodeViolations(): array
    {
        $rows = Affiliate::query()
            ->toBase()
            ->join('brands', 'brands.id', '=', 'affiliates.brand_id')
            ->whereColumn('affiliates.category_id', '<>', 'brands.category_id')
            ->orderBy('affiliates.id')
            ->get([
                'affiliates.id', 'affiliates.category_id', 'brands.id as brand_id', 'brands.name as brand_name',
                'brands.category_id as brand_category_id',
            ]);

        $violations = [];
        foreach ($rows as $row) {
            $violations[] = [
                self::AFFILIATE_BRAND_NODE,
                sprintf('アフィリエイト #%s', self::str($row->id)),
                sprintf(
                    'ノードは %s、銘柄 #%s %s の所属は %s',
                    $this->nodeLabel($row->category_id),
                    self::str($row->brand_id),
                    self::str($row->brand_name),
                    $this->nodeLabel($row->brand_category_id),
                ),
            ];
        }

        return $violations;
    }

    /**
     * recipe_count は数え直しのたびに書く持ち値なので、数え直しを呼び忘れた書き換えがあるとずれる。数え方は
     * RecipeCounter だけが持つので、同じ数え方で数え直した値と比べる。
     *
     * @return list<array{string, string, string}>
     */
    private function recipeCountViolations(RecipeCounter $counter): array
    {
        $expected = $counter->countsByCocktail();

        $violations = [];
        foreach (Cocktail::query()->orderBy('id')->get(['id', 'name', 'recipe_count']) as $cocktail) {
            $count = $expected[$cocktail->id] ?? 0;
            if ($cocktail->recipe_count !== $count) {
                $violations[] = [
                    self::RECIPE_COUNT,
                    $this->cocktailLabel($cocktail->id, $cocktail->name),
                    "いま {$cocktail->recipe_count}・数え直すと {$count}",
                ];
            }
        }

        return $violations;
    }

    /**
     * 根は旧の種別で、旧の材料は種別を指せなかった(#153)。根を材料にすると、あるもの検索・材料の数で
     * 種別まるごとが 1 つの材料になる。
     *
     * @return list<array{string, string, string}>
     */
    private function rootIngredientViolations(CategoryTree $tree): array
    {
        $rows = CocktailIngredient::query()
            ->toBase()
            ->join('cocktails', 'cocktails.id', '=', 'cocktail_ingredients.cocktail_id')
            ->whereIn('cocktail_ingredients.category_id', $tree->rootIds())
            ->orderBy('cocktail_ingredients.cocktail_id')
            ->orderBy('cocktail_ingredients.position')
            ->get([
                'cocktail_ingredients.cocktail_id', 'cocktails.name as cocktail_name',
                'cocktail_ingredients.position', 'cocktail_ingredients.category_id',
            ]);

        $violations = [];
        foreach ($rows as $row) {
            $violations[] = [
                self::ROOT_INGREDIENT,
                $this->ingredientLabel($row->cocktail_id, $row->cocktail_name, $row->position),
                "材料のノード {$this->nodeLabel($row->category_id)} が根",
            ];
        }

        return $violations;
    }

    /**
     * ベースも材料と同じく、旧では種別を指せなかった(#153)。
     *
     * @return list<array{string, string, string}>
     */
    private function rootBaseViolations(CategoryTree $tree): array
    {
        $cocktails = Cocktail::query()
            ->whereIn('base_category_id', $tree->rootIds())
            ->orderBy('id')
            ->get(['id', 'name', 'base_category_id']);

        $violations = [];
        foreach ($cocktails as $cocktail) {
            $violations[] = [
                self::ROOT_BASE,
                $this->cocktailLabel($cocktail->id, $cocktail->name),
                "ベースのノード {$this->nodeLabel($cocktail->base_category_id)} が根",
            ];
        }

        return $violations;
    }

    /**
     * 有名な銘柄はノードの代表で、材料が銘柄を持たないときの度数の元になる。部分木の外の銘柄だと、材料と関係の無い
     * 系統の度数で計算される(docs/decisions/strength.md の決まったこと 3)。役割によらず、有名な銘柄を持つノードはすべて見る。
     *
     * @return list<array{string, string, string}>
     */
    private function famousBrandSubtreeViolations(): array
    {
        $rows = Category::query()
            ->toBase()
            ->join('brands', 'brands.id', '=', 'categories.famous_brand_id')
            ->whereNotExists(fn (QueryBuilder $closure) => $closure
                ->from('category_closures')
                ->whereColumn('category_closures.ancestor_id', 'categories.id')
                ->whereColumn('category_closures.descendant_id', 'brands.category_id'))
            ->orderBy('categories.id')
            ->get(['categories.id', 'brands.id as brand_id', 'brands.name as brand_name', 'brands.category_id as brand_category_id']);

        $violations = [];
        foreach ($rows as $row) {
            $violations[] = [
                self::FAMOUS_BRAND_SUBTREE,
                "ノード {$this->nodeLabel($row->id)}",
                sprintf(
                    '有名な銘柄 #%s %s の所属 %s が、このノードの部分木に無い',
                    self::str($row->brand_id),
                    self::str($row->brand_name),
                    $this->nodeLabel($row->brand_category_id),
                ),
            ];
        }

        return $violations;
    }

    /**
     * 有名な銘柄が要るノード(CategoryTree::isFamousBrandOptional が偽になるノード)で、有名な銘柄が無いもの。度数の計算で、
     * 銘柄を持たない材料が 0% になる。保存のときの検証で必須にする決まりだが、移行したデータには残りうる
     * (docs/decisions/strength.md)。
     * 根は材料に使えず度数の計算に出てこないので除く。
     *
     * @return list<array{string, string, string}>
     */
    private function missingFamousBrandWarnings(CategoryTree $tree): array
    {
        $idColumn = (new Category)->qualifyColumn('id');
        $nodes = Category::query()
            ->whereNull('famous_brand_id')
            ->whereNotIn($idColumn, $tree->rootIds())
            ->whereNotIn($idColumn, $tree->famousBrandOptionalNodeIds())
            ->orderBy('id')
            ->get(['id']);

        $warnings = [];
        foreach ($nodes as $node) {
            $warnings[] = [
                self::MISSING_FAMOUS_BRAND,
                "ノード {$this->nodeLabel($node->id)}",
                '銘柄を持たない材料の度数が 0% になる',
            ];
        }

        return $warnings;
    }

    /**
     * 説明文の参照の記法(DescriptionParser が画面に出すときと同じ形)が指す先が無いものと、旧の記法(`@p`・`@m`)が残ったもの。
     * 指す先の無い参照は、画面では黙って取り除かれ、旧の記法はリンクにならずに文字のまま出る(#285)。
     *
     * @return list<array{string, string, string}>
     */
    private function descriptionReferenceViolations(): array
    {
        $references = [];
        $legacyViolations = [];
        foreach (self::DESCRIPTION_COLUMNS as $table => [$label, $columns]) {
            $rows = DB::table($table)
                ->where(function (QueryBuilder $query) use ($columns) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, 'like', '%@%')->orWhere($column, 'like', '%＠%');
                    }
                })
                ->orderBy('id')
                ->get(['id', 'name', ...$columns]);

            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    $text = $row->{$column};
                    if (! is_string($text)) {
                        continue;
                    }
                    $where = sprintf('%s #%s %s の %s', $label, self::str($row->id), self::str($row->name), $column);

                    preg_match_all(DescriptionParser::REFERENCE_PATTERN, $text, $matches, PREG_SET_ORDER);
                    foreach ($matches as $m) {
                        $references[] = [$where, $m[0], strtolower($m[1]), (int) $m[2]];
                    }

                    preg_match_all(self::LEGACY_REFERENCE_PATTERN, $text, $legacyMatches);
                    foreach ($legacyMatches[0] as $legacy) {
                        $legacyViolations[] = [
                            self::DESCRIPTION_LEGACY_REFERENCE,
                            $where,
                            "旧の記法 {$legacy} が残っている(@n<ノードの ID>@ に直す)",
                        ];
                    }

                    // 旧の記法の @ も記法の外の @ になるので、旧の記法が残る列は、上の 1 つだけで挙げる
                    $strayAt = $legacyMatches[0] === [] ? DescriptionMarkup::strayAtContext($text) : null;
                    if ($strayAt !== null) {
                        $legacyViolations[] = [
                            self::DESCRIPTION_STRAY_AT,
                            $where,
                            "記法になっていない @ がある(管理画面で保存できない): {$strayAt}",
                        ];
                    }
                }
            }
        }

        $existing = $this->existingReferenceTargets($references);
        $violations = [];
        foreach ($references as [$where, $reference, $kind, $id]) {
            if (! isset($existing[$kind][$id])) {
                $violations[] = [
                    self::DESCRIPTION_REFERENCE_TARGET,
                    $where,
                    "{$reference} の指す先の".self::REFERENCE_TARGETS[$kind][1]." #{$id} が無い",
                ];
            }
        }

        return [...$violations, ...$legacyViolations];
    }

    /**
     * 参照が指す先のうち、あるものを、種類ごとに 1 回の問い合わせで引く。
     *
     * @param  list<array{string, string, string, int}>  $references  [どこの参照か, 記法, 種類, ID]
     * @return array<string, array<int, true>> 種類 => (ある ID => true)
     */
    private function existingReferenceTargets(array $references): array
    {
        $ids = [];
        foreach ($references as [, , $kind, $id]) {
            $ids[$kind][$id] = $id;
        }

        $existing = [];
        foreach ($ids as $kind => $kindIds) {
            foreach (DB::table(self::REFERENCE_TARGETS[$kind][0])->whereIn('id', array_values($kindIds))->pluck('id') as $id) {
                $existing[$kind][(int) self::str($id)] = true;
            }
        }

        return $existing;
    }

    private function cocktailLabel(mixed $id, mixed $name): string
    {
        return sprintf('カクテル #%s %s', self::str($id), self::str($name));
    }

    private function ingredientLabel(mixed $cocktailId, mixed $cocktailName, mixed $position): string
    {
        return sprintf('%s の %s 行目', $this->cocktailLabel($cocktailId, $cocktailName), self::str($position));
    }

    private function nodeLabel(mixed $id): string
    {
        $id = (int) self::str($id);

        return "#{$id} ".($this->nodeNames[$id] ?? '(無いノード)');
    }

    /**
     * クエリビルダーで読んだ列の値(ドライバーによって int か文字列)を、表に出す文字列にする。
     */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
