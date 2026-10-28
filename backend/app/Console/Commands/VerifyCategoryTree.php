<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use Illuminate\Console\Command;

/**
 * カテゴリ木の閉包表(category_closures)が、木として正しいかを調べる。読むだけで、何も書き換えない。
 *
 * 親子は閉包表だけで持つ(docs/schema.md の「categories」)ので、行が 1 行欠けたり余ったりすると、パンくず・部分木の
 * 検索・根の役割の判定が黙って食い違う。正しい閉包表は depth 1 の行(親)から一意に作り直せるので、
 * 親をたどって作り直した行と、いまの行を比べる。
 *
 * 親の行は閉包表にしか無いため、深さ 1 の葉の親の行だけが欠けた木は、正しい別の根と見分けられない。
 */
final class VerifyCategoryTree extends Command
{
    protected $signature = 'tree:verify';

    protected $description = 'カテゴリ木の閉包表(category_closures)が木として正しいかを調べ、破れていれば一覧を出して失敗する';

    private const SELF_ROW = '自分の行(depth 0)';

    private const PARENT_ROW = '親(depth 1 の行)';

    private const CYCLE = '循環';

    private const ANCESTOR_ROWS = '祖先の行';

    /** @var array<int, string> ノードの ID → 名前 */
    private array $names = [];

    /** @var array<int, array<int, int>> 子孫の ID → (祖先の ID → depth) */
    private array $ancestorsOf = [];

    /** @var array<int, true> 根(CategoryTree::rootIds)の ID */
    private array $rootIds = [];

    /** @var array<int, list<int>> 子の ID → 親の ID(depth 1 の行の祖先)。正しい木なら 1 つ */
    private array $parentsOf = [];

    /** @var array<int, int> 根でないノードの ID → 1 つに決まった親の ID。親をたどる処理はこれだけを見る */
    private array $parentOf = [];

    /** @var list<list<int>> 親をたどると戻ってくる輪(ノードの ID の小さい順) */
    private array $cycles = [];

    /** @var array<int, true> 根ではないのに親が 1 つに決まらないか、輪に入るノード */
    private array $unresolvable = [];

    public function handle(CategoryTree $tree): int
    {
        $this->load($tree);
        $this->resolveParents();

        $violations = [
            ...$this->selfRowViolations(),
            ...$this->parentViolations(),
            ...$this->cycleViolations(),
            ...$this->ancestorRowViolations(),
        ];

        if ($violations === []) {
            $this->info(sprintf('問題なし(ノード %d 件)', count($this->names)));

            return self::SUCCESS;
        }

        $this->error(sprintf('カテゴリ木の整合性が破れている(%d 件):', count($violations)));
        $this->table(['調べたこと', 'ノード', '内容'], $violations);

        return self::FAILURE;
    }

    private function load(CategoryTree $tree): void
    {
        foreach (Category::query()->whereIn((new Category)->qualifyColumn('id'), $tree->rootIds())->get(['id']) as $root) {
            $this->rootIds[$root->id] = true;
        }

        foreach (Category::query()->orderBy('id')->get(['id', 'name']) as $category) {
            $this->names[$category->id] = $category->name;
        }

        foreach (CategoryClosure::query()->orderBy('descendant_id')->orderBy('depth')->orderBy('ancestor_id')->get() as $row) {
            /** @var CategoryClosure $row */
            $ancestor = (int) $row->ancestor_id;
            $descendant = (int) $row->descendant_id;
            $this->ancestorsOf[$descendant][$ancestor] = $row->depth;
            if ($row->depth === 1) {
                $this->parentsOf[$descendant][] = $ancestor;
            }
        }
    }

    /**
     * 自分の行が無いと、addNode・moveSubtree がそのノードを木に入っていないとみなして操作を断る。
     *
     * @return list<array{string, string, string}>
     */
    private function selfRowViolations(): array
    {
        $violations = [];
        foreach (array_keys($this->names) as $id) {
            $depth = $this->ancestorsOf[$id][$id] ?? null;
            if ($depth === null) {
                $violations[] = [self::SELF_ROW, $this->label($id), '自分から自分への行が無い'];
            } elseif ($depth !== 0) {
                $violations[] = [self::SELF_ROW, $this->label($id), "自分から自分への行の depth が {$depth}"];
            }
        }

        return $violations;
    }

    /**
     * 根でないノードの親を 1 つに決め、決まらないノードと輪を集める。親の行が 0 個・2 個以上のノードや輪に入るノードは
     * 作り直す閉包表が決まらないので、どの判定もここで決めた結果だけを見る(判定ごとに親の数を数え直さない)。
     */
    private function resolveParents(): void
    {
        foreach (array_keys($this->names) as $id) {
            if (isset($this->rootIds[$id])) {
                continue;
            }
            $parents = $this->parentsOf[$id] ?? [];
            if (count($parents) === 1) {
                $this->parentOf[$id] = $parents[0];
            } else {
                $this->unresolvable[$id] = true;
            }
        }

        /** @var array<int, true> $finished 輪に入らないと分かったか、輪として集めたノード */
        $finished = [];
        foreach (array_keys($this->parentOf) as $start) {
            $path = [];
            $current = $start;
            while (! isset($finished[$current]) && isset($this->parentOf[$current])) {
                if (isset($path[$current])) {
                    $cycle = $this->cycleFrom($current);
                    $this->cycles[] = $cycle;
                    foreach ($cycle as $id) {
                        $this->unresolvable[$id] = true;
                    }
                    break;
                }
                $path[$current] = true;
                $current = $this->parentOf[$current];
            }
            $finished += $path;
        }
    }

    /**
     * $start から親をたどって $start に戻るまでのノード(ID の小さい順)。
     *
     * @return list<int>
     */
    private function cycleFrom(int $start): array
    {
        $cycle = [$start];
        for ($current = $this->parentOf[$start]; $current !== $start; $current = $this->parentOf[$current]) {
            $cycle[] = $current;
        }
        sort($cycle);

        return $cycle;
    }

    /**
     * 根でない(自分より上の祖先からの行がある)のに、親が 1 つに決まらないと、パンくずや forest がどの親につなげるかが決まらない。
     *
     * @return list<array{string, string, string}>
     */
    private function parentViolations(): array
    {
        $violations = [];
        foreach (array_keys($this->names) as $id) {
            if (isset($this->rootIds[$id]) || isset($this->parentOf[$id])) {
                continue;
            }
            $parents = $this->parentsOf[$id] ?? [];
            $violations[] = [self::PARENT_ROW, $this->label($id), $parents === []
                ? '祖先の行があるのに、親の行が無い'
                : sprintf(
                    '親の行が %d つある(%s)',
                    count($parents),
                    implode('・', array_map(fn (int $parent): string => $this->label($parent), $parents)),
                )];
        }

        return $violations;
    }

    /**
     * 親をたどって自分に戻ると、根に届かない。輪ごとに 1 件にまとめる。
     *
     * @return list<array{string, string, string}>
     */
    private function cycleViolations(): array
    {
        return array_map(fn (array $cycle): array => [
            self::CYCLE,
            implode('・', array_map(fn (int $id): string => $this->label($id), $cycle)),
            '親をたどると自分に戻る',
        ], $this->cycles);
    }

    /**
     * 閉包表は、各ノードの親をたどった祖先それぞれへの行(depth は段数)だけでできているはず。作り直した行と
     * いまの行を比べ、足りない行・余分な行・depth が違う行を挙げる。
     *
     * 親が決まらないノードや輪を通るノードは作り直せないので比べない(同じ破れを「親」「循環」と重ねて挙げないため)。
     * 自分の行(depth 0)も「自分の行」で見るので比べない。
     *
     * @return list<array{string, string, string}>
     */
    private function ancestorRowViolations(): array
    {
        $violations = [];
        foreach (array_keys($this->names) as $id) {
            $expected = $this->rebuiltAncestors($id);
            if ($expected === null) {
                continue;
            }
            $actual = $this->ancestorsOf[$id] ?? [];
            unset($actual[$id]);

            $problems = [];
            foreach ($expected as $ancestor => $depth) {
                if (! isset($actual[$ancestor])) {
                    $problems[] = "足りない行 {$this->label($ancestor)}(depth {$depth})";
                } elseif ($actual[$ancestor] !== $depth) {
                    $problems[] = "{$this->label($ancestor)} からの行の depth が違う(いま {$actual[$ancestor]}・正しくは {$depth})";
                }
            }
            foreach ($actual as $ancestor => $depth) {
                if (! isset($expected[$ancestor])) {
                    $problems[] = "余分な行 {$this->label($ancestor)}(depth {$depth})";
                }
            }

            foreach ($problems as $problem) {
                $violations[] = [self::ANCESTOR_ROWS, $this->label($id), $problem];
            }
        }

        return $violations;
    }

    /**
     * $id から親をたどった祖先 → 段数。自分は含めない。途中に親が決まらないノードや輪があれば null。
     *
     * @return array<int, int>|null
     */
    private function rebuiltAncestors(int $id): ?array
    {
        $ancestors = [];
        $current = $id;
        for ($depth = 1; ; $depth++) {
            if (isset($this->unresolvable[$current])) {
                return null;
            }
            if (! isset($this->parentOf[$current])) {
                return $ancestors;
            }
            $current = $this->parentOf[$current];
            $ancestors[$current] = $depth;
        }
    }

    private function label(int $id): string
    {
        return "#{$id} {$this->names[$id]}";
    }
}
