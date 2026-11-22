<?php

namespace Database\Seeders;

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Services\CategoryTree;
use App\Services\RecipeCounter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 条件検索の性能を測る(#96)ための、移行後と同じ規模のデータ。カクテル 5,700・材料 27,000・ノード 600。
 *
 * 本番のデータは使わず、形だけを本番に寄せた作り物を入れる。乱数の種を決めてあるので、何度入れても同じデータになる。
 *
 * - 木: 根 5 つ(役割の無い 3 つと、ソフトドリンク・その他)。根の下にカテゴリ、その下にミドル、洋酒の根の下は
 *   さらに 1〜2 段深くする(旧の 3 段より深い木でも閉包表の引き方が重くならないかを見るため)
 * - 材料: 1 つ目はベース(洋酒のノード)。残りは洋酒・ソフトドリンク・その他から、よく使われるノードに偏らせて選ぶ。
 *   一部を任意にする。銘柄は付けない(条件検索は銘柄を見ない)
 * - カクテル: 一部を下書きにする。技法は 6 つ。並べ替えの列(甘さ・評価・動画)は、値の無いものも混ぜる
 *
 * 入れ方: `php artisan migrate:fresh --seeder=SearchPerformanceSeeder`(テストの TC-96 もこれを使う)
 */
class SearchPerformanceSeeder extends Seeder
{
    public const NODES = 600;

    public const COCKTAILS = 5700;

    public const INGREDIENTS = 27000;

    /**
     * 根の名前・役割・材料の分け方・その下に作るカテゴリの数。カテゴリの数の合計(98)と根(5)とミドル(カテゴリごとに 4)で
     * 495 になり、残りは洋酒の根の下を深くして 600 にそろえる。
     *
     * @var list<array{string, CategoryRole|null, 'liquor'|'soft'|'other', int}>
     */
    private const ROOTS = [
        ['蒸留酒', null, 'liquor', 10],
        ['醸造酒', null, 'liquor', 8],
        ['混成酒', null, 'liquor', 30],
        ['ソフトドリンク', CategoryRole::SoftDrink, 'soft', 25],
        ['その他', CategoryRole::Other, 'other', 25],
    ];

    private const MIDDLES_PER_CATEGORY = 4;

    /** 技法の数(旧と同じ 6 つ) */
    private const METHODS = 6;

    private Randomizer $random;

    private CategoryTree $tree;

    /** これまでに作ったノードの数 */
    private int $nodeCount = 0;

    public function run(): void
    {
        $this->random = new Randomizer(new Mt19937(96));
        $this->tree = app(CategoryTree::class);

        $nodes = $this->seedTree();
        $methodIds = $this->seedMethods();
        $this->seedCocktails($nodes, $methodIds);
    }

    /**
     * 木を作り、材料に使うノード(根を除く)を、洋酒・ソフトドリンク・その他に分けて返す。
     *
     * @return array{liquor: list<int>, soft: list<int>, other: list<int>}
     */
    private function seedTree(): array
    {
        $groups = ['liquor' => [], 'soft' => [], 'other' => []];
        $liquorMiddles = [];
        foreach (self::ROOTS as [$rootName, $role, $group, $categories]) {
            $root = $this->addNode(null, $rootName, $role);
            for ($c = 1; $c <= $categories; $c++) {
                $category = $this->addNode($root, "{$rootName}{$c}");
                $groups[$group][] = $category->id;
                for ($m = 1; $m <= self::MIDDLES_PER_CATEGORY; $m++) {
                    $middle = $this->addNode($category, "{$rootName}{$c}-{$m}");
                    $groups[$group][] = $middle->id;
                    if ($group === 'liquor') {
                        $liquorMiddles[] = $middle;
                    }
                }
            }
        }

        // 残りは、洋酒のミドルの下を順に 1 段深くする。6 つ目ごとに、直前に作ったノードの下をもう 1 段深くする
        $remaining = self::NODES - $this->nodeCount;
        $child = null;
        for ($i = 0; $i < $remaining; $i++) {
            if ($child !== null && $i % 6 === 5) {
                $groups['liquor'][] = $this->addNode($child, "{$child->name}-x")->id;

                continue;
            }
            $middle = $liquorMiddles[$i % count($liquorMiddles)];
            $child = $this->addNode($middle, "{$middle->name}-{$i}");
            $groups['liquor'][] = $child->id;
        }

        return $groups;
    }

    /**
     * ノードを 1 つ作る。閉包表は CategoryTree 経由でだけ書き換える決まりなので、addNode で作る。
     */
    private function addNode(?Category $parent, string $name, ?CategoryRole $role = null): Category
    {
        $this->nodeCount++;

        return $this->tree->addNode($parent, [
            'name' => $name,
            'name_search' => $name,
            'name_eng' => 'node '.$this->nodeCount,
            'role' => $role,
            'is_major' => $this->nodeCount % 3 === 0,
        ]);
    }

    /**
     * @return list<int>
     */
    private function seedMethods(): array
    {
        $ids = [];
        for ($i = 1; $i <= self::METHODS; $i++) {
            $ids[] = DB::table('methods')->insertGetId([
                'name' => "技法{$i}",
                'name_eng' => "method {$i}",
                'description' => '',
            ]);
        }

        return $ids;
    }

    /**
     * カクテルと材料を入れ、材料の数(recipe_count)を RecipeCounter で数えて書く。
     *
     * @param  array{liquor: list<int>, soft: list<int>, other: list<int>}  $nodes
     * @param  list<int>  $methodIds
     */
    private function seedCocktails(array $nodes, array $methodIds): void
    {
        $counts = $this->ingredientCounts();
        $now = now();
        $cocktails = [];
        $ingredients = [];
        foreach ($counts as $index => $ingredientCount) {
            $id = $index + 1;
            $base = $this->popular($nodes['liquor']);
            $name = $this->katakana();
            $cocktails[] = [
                'id' => $id,
                'name' => $name,
                'name_search' => $name,
                'name_eng' => $this->random->getInt(1, 10) === 1 ? null : "cocktail {$id}",
                'base_category_id' => $base,
                'method_id' => $methodIds[$this->random->getInt(0, count($methodIds) - 1)],
                'volume' => $this->random->getInt(0, 400) / 10,
                'sweet_degree' => $this->random->getInt(1, 5) === 1 ? null : $this->random->getInt(1, 5),
                'rate' => $this->random->getInt(1, 3) === 1 ? null : $this->random->getInt(1, 5),
                'is_draft' => $this->random->getInt(1, 30) === 1,
                'is_major' => $this->random->getInt(1, 10) === 1,
                'youtube' => $this->random->getInt(1, 5) === 1 ? "video{$id}" : null,
                'created_at' => $now,
                'updated_at' => $now->copy()->subMinutes($id),
            ];

            for ($position = 1; $position <= $ingredientCount; $position++) {
                $ingredients[] = [
                    'cocktail_id' => $id,
                    'position' => $position,
                    'category_id' => $position === 1 ? $base : $this->ingredientNode($nodes),
                    'brand_id' => null,
                    'amount' => '30ml',
                    'is_optional' => $position > 1 && $this->random->getInt(1, 20) === 1,
                ];
            }
        }

        foreach (array_chunk($cocktails, 500) as $chunk) {
            DB::table('cocktails')->insert($chunk);
        }
        foreach (array_chunk($ingredients, 1000) as $chunk) {
            DB::table('cocktail_ingredients')->insert($chunk);
        }

        app(RecipeCounter::class)->recount(range(1, self::COCKTAILS));
    }

    /**
     * カクテルごとの材料の行数。合計を INGREDIENTS にそろえ、1〜9 行にばらつかせる。
     *
     * @return list<int>
     */
    private function ingredientCounts(): array
    {
        $low = intdiv(self::INGREDIENTS, self::COCKTAILS);
        $extra = self::INGREDIENTS - $low * self::COCKTAILS;
        $counts = [];
        for ($i = 0; $i < self::COCKTAILS; $i++) {
            $counts[] = $i < $extra ? $low + 1 : $low;
        }

        // 合計を変えずに、2 つのカクテルのあいだで 1 行ずつ移してばらつかせる
        for ($i = 0; $i < self::COCKTAILS * 2; $i++) {
            $from = $this->random->getInt(0, self::COCKTAILS - 1);
            $to = $this->random->getInt(0, self::COCKTAILS - 1);
            if ($counts[$from] > 1 && $counts[$to] < 9) {
                $counts[$from]--;
                $counts[$to]++;
            }
        }

        return $this->random->shuffleArray($counts);
    }

    /**
     * ベースでない材料のノード。洋酒 4・ソフトドリンク 3・その他 3 の割合。
     *
     * @param  array{liquor: list<int>, soft: list<int>, other: list<int>}  $nodes
     */
    private function ingredientNode(array $nodes): int
    {
        $roll = $this->random->getInt(1, 10);

        return $this->popular($roll <= 4 ? $nodes['liquor'] : ($roll <= 7 ? $nodes['soft'] : $nodes['other']));
    }

    /**
     * $ids から 1 つ選ぶ。前のほうほど選ばれやすくする(よく使われる材料に偏る本番に寄せる)。
     *
     * @param  list<int>  $ids
     */
    private function popular(array $ids): int
    {
        $r = $this->random->nextFloat();

        return $ids[(int) floor(count($ids) * $r * $r)];
    }

    private function katakana(): string
    {
        $letters = mb_str_split('アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワンガギグゲゴザジズゼゾダデドバビブベボパピプペポー');
        $length = $this->random->getInt(3, 10);
        $name = '';
        for ($i = 0; $i < $length; $i++) {
            $name .= $letters[$this->random->getInt(0, count($letters) - 1)];
        }

        return $name;
    }
}
