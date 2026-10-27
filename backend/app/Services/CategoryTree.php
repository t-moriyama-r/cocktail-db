<?php

namespace App\Services;

use App\Enums\CategoryDeleteBlocker;
use App\Enums\CategoryRole;
use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * カテゴリ木(categories と閉包表 category_closures)を操作する。
 *
 * 閉包表は、1 つのノードについて自分自身(depth 0)とすべての祖先からの行がそろっていないと、
 * パンくず・部分木の検索・根の役割の判定が食い違う。行の書き換えをこのクラスに閉じ込め、
 * 書き換えは 1 つのトランザクションで行う。
 */
class CategoryTree
{
    /**
     * 判定ごとの、当てはまる根の役割(docs/decisions/category-root-role.md の「判定の対応」)。
     * 「有名な銘柄が要らない」と「洋酒ではない」は今は同じ役割の組だが、旧でも別の判定(kind 4・5 と kind >= 4)だった。
     * 根の役割が増えたときに片方の対応だけを変えられるよう、分けて持つ。
     */
    private const OTHER_ROLES = [CategoryRole::Other];

    private const FAMOUS_BRAND_OPTIONAL_ROLES = [CategoryRole::SoftDrink, CategoryRole::Other];

    private const NON_LIQUOR_ROLES = [CategoryRole::SoftDrink, CategoryRole::Other];

    /**
     * $parent の下(null なら根)にノードを作り、閉包表に自分の行と、すべての祖先からの行を足す。
     *
     * 親の祖先の行は親の閉包表の行から写すので、親が木に入っていない(閉包表に自分の行が無い)と、
     * 祖先とつながらない根として残ってしまう。そのときは例外にして、作ったノードも戻す。
     *
     * 役割(role)は根だけが持つ(docs/decisions/category-root-role.md)。根でないノードに役割を入れると、そのノードの
     * 値が判定に使われないまま残り、根の役割と食い違って見えるので、親のあるノードには持たせない。
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws InvalidArgumentException 親が保存されていない・木に入っていないとき、親のあるノードに役割を持たせようとしたとき
     */
    public function addNode(?Category $parent, array $attrs): Category
    {
        if ($parent !== null && ($attrs['role'] ?? null) !== null) {
            throw new InvalidArgumentException('役割を持てるのは根だけ(親のあるノードには role を入れない)');
        }

        return DB::transaction(function () use ($parent, $attrs) {
            $node = Category::create($attrs);

            CategoryClosure::query()->insert([
                'ancestor_id' => $node->id,
                'descendant_id' => $node->id,
                'depth' => 0,
            ]);

            if ($parent !== null) {
                $copied = CategoryClosure::query()->insertUsing(
                    ['ancestor_id', 'descendant_id', 'depth'],
                    CategoryClosure::query()
                        ->where('descendant_id', $parent->getKey())
                        ->selectRaw('ancestor_id, ?, depth + 1', [$node->id]),
                );
                if ($copied === 0) {
                    throw new InvalidArgumentException('親のノードがカテゴリ木に入っていない(保存されていない、または閉包表に自分の行が無い)');
                }
            }

            return $node;
        });
    }

    /**
     * $node の部分木を、$newParent の下(null なら根)へ移す。
     *
     * 消すのは、部分木の中のノードを指す行のうち、祖先が部分木の外にあるものだけ。部分木の中どうしの行は、
     * 移しても段数が変わらないので残し、部分木と関係の無いノードの行には触れない(旧は親の付け替えで、
     * 関係の無いレコードまで書き換えていた)。新しい祖先からの行は、新しい親の祖先の行と部分木の行の
     * 組み合わせで足す。
     *
     * 自分や子孫の下へ移すと親子が輪になり、根に届かないノードができる。親や移すノードが木に入っていないと、
     * 祖先からの行が写せず、黙って根になってしまう。どちらも何も書き換えずに例外にする。
     *
     * 役割を持つ根を別のノードの下へ移すと、根でないノードが役割を持つことになる(役割は根だけが持つ)ので、これも止める。
     * 役割の無い部分木を別の根の下へ移すのは許し、移した先の根の役割になる(#153 の決まったこと)。
     *
     * 移して部分木の根の役割が変わったときは、部分木のノード(とそこにぶら下がる銘柄)を材料に使うカクテルの
     * 材料の数を、同じトランザクションで数え直す(「その他」の根に出入りすると数が変わる。#156)。
     *
     * @throws InvalidArgumentException 移すノード・親が木に入っていないとき、親が自分か自分の子孫のとき、役割を持つ根を親の下へ移すとき
     */
    public function moveSubtree(Category $node, ?Category $newParent): void
    {
        DB::transaction(function () use ($node, $newParent) {
            if (! $this->isInTree($node)) {
                throw new InvalidArgumentException('移すノードがカテゴリ木に入っていない(保存されていない、または閉包表に自分の行が無い)');
            }
            $subtreeIds = $this->subtreeIds($node);
            $roleBefore = $this->rootRole($node);

            if ($newParent !== null) {
                if (Category::query()->whereKey($node->getKey())->whereNotNull('role')->exists()) {
                    throw new InvalidArgumentException('役割を持つ根は、ほかのノードの下へ移せない(役割を持てるのは根だけ)');
                }
                if (! $this->isInTree($newParent)) {
                    throw new InvalidArgumentException('新しい親のノードがカテゴリ木に入っていない(保存されていない、または閉包表に自分の行が無い)');
                }
                if (in_array($newParent->getKey(), $subtreeIds, true)) {
                    throw new InvalidArgumentException('自分自身・自分の子孫の下へは移せない');
                }
            }

            CategoryClosure::query()
                ->whereIn('descendant_id', $subtreeIds)
                ->whereNotIn('ancestor_id', $subtreeIds)
                ->delete();

            if ($newParent !== null) {
                CategoryClosure::query()->insertUsing(
                    ['ancestor_id', 'descendant_id', 'depth'],
                    DB::table('category_closures as p')
                        ->crossJoin('category_closures as s')
                        ->where('p.descendant_id', $newParent->getKey())
                        ->where('s.ancestor_id', $node->getKey())
                        ->selectRaw('p.ancestor_id, s.descendant_id, p.depth + s.depth + 1'),
                );
            }

            if ($this->rootRole($node) !== $roleBefore) {
                // RecipeCounter は判定(otherNodeIds)にこのクラスを使うので、コンストラクタで受けると互いを要して作れない。
                // ここで作って渡す
                (new RecipeCounter($this))->recountSubtree($node);
            }
        });
    }

    /**
     * 葉の $node を、閉包表の行(自分の行と、祖先からの行)ごと消す。
     *
     * 子があるノードを消すと、子は祖先とのつながりを失って根に化けるので、消せるのは葉だけにする。部分木をまとめて
     * 消す操作は作らない(docs/schema.md の「カテゴリを消すとき」)。銘柄・材料・ベース・アフィリエイトから
     * 指されているノードは外部キーの RESTRICT でも消えないが、それではどれが邪魔をしているのかが分からないので、
     * 先に調べて理由をすべて例外に載せる(管理画面に出す。#121)。
     *
     * 調べてから消すまでのあいだに子や銘柄を足されると、調べた結果が古くなる。ノードの行と、閉包表の自分を
     * 子孫とする行をロックしてから調べる(子を足す addNode は親のそれらの行を読み、銘柄などを足すときは
     * 外部キーの確かめでノードの行を読むので、ロックが外れるまで待つ)。
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     * @throws CategoryNotDeletableException 消せない理由があるとき
     */
    public function deleteLeaf(Category $node): void
    {
        DB::transaction(function () use ($node) {
            $this->assertInTree($node);
            Category::query()->whereKey($node->getKey())->lockForUpdate()->get();
            CategoryClosure::query()->where('descendant_id', $node->getKey())->lockForUpdate()->get();

            $blockers = $this->deleteBlockers($node);
            if ($blockers !== []) {
                throw new CategoryNotDeletableException($blockers);
            }

            // 閉包表の行は外部キーの CASCADE で一緒に消える
            Category::query()->whereKey($node->getKey())->delete();
        });
    }

    /**
     * $node を deleteLeaf で消せない理由。消せるなら空。理由は CategoryDeleteBlocker の定義の順。
     *
     * 見るのは $node を直接指すものだけ。子孫を指すものは、子がある(Children)ことで止まる。
     *
     * @return list<CategoryDeleteBlocker>
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    public function deleteBlockers(Category $node): array
    {
        $this->assertInTree($node);
        $id = $node->getKey();

        $found = [
            CategoryDeleteBlocker::Children->value => CategoryClosure::query()
                ->where('ancestor_id', $id)->where('depth', '>', 0)->exists(),
            CategoryDeleteBlocker::Brands->value => Brand::query()->where('category_id', $id)->exists(),
            CategoryDeleteBlocker::Ingredients->value => CocktailIngredient::query()->where('category_id', $id)->exists(),
            CategoryDeleteBlocker::BaseCocktails->value => Cocktail::query()->where('base_category_id', $id)->exists(),
            CategoryDeleteBlocker::Affiliates->value => Affiliate::query()->where('category_id', $id)->exists(),
        ];

        return array_values(array_filter(
            CategoryDeleteBlocker::cases(),
            fn (CategoryDeleteBlocker $blocker): bool => $found[$blocker->value],
        ));
    }

    /**
     * 根から $node までのパンくず。根が先頭で、$node が末尾。
     *
     * @return Collection<int, Category>
     */
    public function path(Category $node): Collection
    {
        return $node->ancestors()
            ->orderByPivot('depth', 'desc')
            ->get()
            ->push($node);
    }

    /**
     * $node と、そのすべての子孫の ID。
     *
     * @return list<int>
     */
    public function subtreeIds(Category $node): array
    {
        $descendantIds = $node->descendants()->get()->map(fn (Category $descendant): int => $descendant->id);

        return [$node->id, ...$descendantIds->values()->all()];
    }

    /**
     * 根(閉包表で祖先を持たないノード)を、兄弟の並び順で返す。
     *
     * @return Collection<int, Category>
     */
    public function roots(): Collection
    {
        $roots = Category::query()->whereIn((new Category)->qualifyColumn('id'), $this->rootIds());
        $this->orderAsSiblings($roots);

        return $roots->get();
    }

    /**
     * 根(閉包表で depth > 0 の行の子孫にならないノード)の ID を、列 id のサブクエリで返す。
     *
     * 親子は閉包表だけで持つので、根かどうかは「自分より上の祖先からの行が無い」ことでしか決まらない。
     */
    public function rootIds(): QueryBuilder
    {
        return DB::table('categories as root_candidate')
            ->select('root_candidate.id')
            ->whereNotExists(fn (QueryBuilder $upper) => $upper
                ->from('category_closures as upper_closure')
                ->whereColumn('upper_closure.descendant_id', 'root_candidate.id')
                ->where('upper_closure.depth', '>', 0));
    }

    /**
     * $node が根(旧の種別にあたるノード)か。
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    public function isRoot(Category $node): bool
    {
        $this->assertInTree($node);

        return DB::query()->fromSub($this->rootIds(), 'roots')->where('roots.id', $node->getKey())->exists();
    }

    /**
     * $node の根の役割。根自身ならその役割。役割の無い根(旧の蒸留酒・醸造酒・混成酒)の部分木では null。
     *
     * 役割は根だけが持つので、ノード自身や途中の祖先の role は見ない。そのため部分木を別の根の下へ移すと、
     * 何も書き換えずに移した先の根の役割になる。
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    public function rootRole(Category $node): ?CategoryRole
    {
        $this->assertInTree($node);

        $idColumn = (new Category)->qualifyColumn('id');
        $root = Category::query()
            ->whereIn($idColumn, $this->rootIds())
            ->whereIn($idColumn, CategoryClosure::query()->select('ancestor_id')->where('descendant_id', $node->getKey()))
            ->first();

        return $root?->role;
    }

    /**
     * 「その他」(旧の kind 5。ガーニッシュなど)のノードか(旧 KindFactory::is_garnish_fromKindId)。
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    public function isOther(Category $node): bool
    {
        return in_array($this->rootRole($node), self::OTHER_ROLES, true);
    }

    /**
     * 有名な銘柄が要らないノードか(旧の kind 4・5。旧 KindFactory::is_kind5_fromKindId)。ソフトドリンクや
     * ガーニッシュには、度数の計算の元になる代表の銘柄が無い。
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    public function isFamousBrandOptional(Category $node): bool
    {
        return in_array($this->rootRole($node), self::FAMOUS_BRAND_OPTIONAL_ROLES, true);
    }

    /**
     * 洋酒ではないノードか(旧の kind >= 4)。
     *
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    public function isNonLiquor(Category $node): bool
    {
        return in_array($this->rootRole($node), self::NON_LIQUOR_ROLES, true);
    }

    /**
     * 「その他」のノード(isOther が true になるノード)の ID を、列 id のサブクエリで返す。
     *
     * ノード 1 つを受ける isOther はクエリの中で使えないので、同じ判定(根を引いてその役割を見る)をサブクエリの形でも持つ。
     */
    public function otherNodeIds(): QueryBuilder
    {
        return $this->nodeIdsWithRootRoleIn(self::OTHER_ROLES);
    }

    /**
     * $node のすぐ下の子を、兄弟の並び順で返す。
     *
     * @return Collection<int, Category>
     */
    public function children(Category $node): Collection
    {
        $children = $node->children();
        $this->orderAsSiblings($children);

        return $children->get();
    }

    /**
     * 木全体を、根から一番深い段まで入れ子で返す。根も子も兄弟の並び順。
     *
     * ノードごとに roots・children を呼ぶと段の数だけクエリが増えるので、ノードと親子(閉包表の depth 1 の行)を
     * まとめて引いて組み立てる。根は roots と同じ定義(rootIds)で決める。
     * 各ノードの根の役割は rootRole と同じく根の role だけを見て、ノード自身や途中の祖先の role は見ない。
     *
     * @return list<CategoryTreeNode>
     */
    public function forest(): array
    {
        $nodes = Category::query();
        $this->orderAsSiblings($nodes);
        $nodes = $nodes->get();

        /** @var array<int, int> $parentIds 子の ID → 親の ID */
        $parentIds = CategoryClosure::query()->where('depth', 1)->pluck('ancestor_id', 'descendant_id')->all();

        /** @var array<int, list<Category>> $childrenOf 親の ID → 子(兄弟の並び順) */
        $childrenOf = [];
        foreach ($nodes as $node) {
            if (isset($parentIds[$node->id])) {
                $childrenOf[$parentIds[$node->id]][] = $node;
            }
        }

        $build = function (Category $node, ?CategoryRole $rootRole) use (&$build, $childrenOf): CategoryTreeNode {
            return new CategoryTreeNode(
                $node,
                $rootRole,
                array_map(fn (Category $child): CategoryTreeNode => $build($child, $rootRole), $childrenOf[$node->id] ?? []),
            );
        };

        $rootIds = Category::query()
            ->whereIn((new Category)->qualifyColumn('id'), $this->rootIds())
            ->pluck('id')
            ->flip()
            ->all();

        return array_values($nodes
            ->filter(fn (Category $node): bool => isset($rootIds[$node->id]))
            ->map(fn (Category $root): CategoryTreeNode => $build($root, $root->role))
            ->all());
    }

    /**
     * $node が閉包表に自分の行(depth 0)を持つか。保存していないノードは持たない。
     */
    private function isInTree(Category $node): bool
    {
        return $node->getKey() !== null && CategoryClosure::query()
            ->where('ancestor_id', $node->getKey())
            ->where('descendant_id', $node->getKey())
            ->where('depth', 0)
            ->exists();
    }

    /**
     * @throws InvalidArgumentException $node が木に入っていないとき
     */
    private function assertInTree(Category $node): void
    {
        if (! $this->isInTree($node)) {
            throw new InvalidArgumentException('ノードがカテゴリ木に入っていない(保存されていない、または閉包表に自分の行が無い)');
        }
    }

    /**
     * 根の役割が $roles のどれかであるノード(根自身を含む)の ID を、列 id のサブクエリで返す。
     *
     * @param  list<CategoryRole>  $roles
     */
    private function nodeIdsWithRootRoleIn(array $roles): QueryBuilder
    {
        return DB::table('category_closures as root_closure')
            ->join('categories as root', 'root.id', '=', 'root_closure.ancestor_id')
            ->select('root_closure.descendant_id as id')
            ->whereIn('root_closure.ancestor_id', $this->rootIds())
            ->whereIn('root.role', array_map(fn (CategoryRole $role): string => $role->value, $roles));
    }

    /**
     * 兄弟の並び順(sort_order、同じなら ID)を $query に付ける。sort_order が同じ兄弟もありうるので、ID で順を決めきる。
     *
     * @param  EloquentBuilder<Category>|BelongsToMany<Category, Category, CategoryClosure>  $query
     */
    private function orderAsSiblings(EloquentBuilder|BelongsToMany $query): void
    {
        $category = new Category;

        $query
            ->orderBy($category->qualifyColumn('sort_order'))
            ->orderBy($category->qualifyColumn('id'));
    }
}
