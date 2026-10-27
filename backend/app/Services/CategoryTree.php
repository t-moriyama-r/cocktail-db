<?php

namespace App\Services;

use App\Enums\CategoryRole;
use App\Models\Category;
use App\Models\CategoryClosure;
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
     * @throws InvalidArgumentException 移すノード・親が木に入っていないとき、親が自分か自分の子孫のとき、役割を持つ根を親の下へ移すとき
     */
    public function moveSubtree(Category $node, ?Category $newParent): void
    {
        DB::transaction(function () use ($node, $newParent) {
            if (! $this->isInTree($node)) {
                throw new InvalidArgumentException('移すノードがカテゴリ木に入っていない(保存されていない、または閉包表に自分の行が無い)');
            }
            $subtreeIds = $this->subtreeIds($node);

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
        });
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

        $role = DB::table('category_closures as root_closure')
            ->join('categories as root', 'root.id', '=', 'root_closure.ancestor_id')
            ->where('root_closure.descendant_id', $node->getKey())
            ->whereIn('root_closure.ancestor_id', $this->rootIds())
            ->value('root.role');

        return $role === null ? null : CategoryRole::from($role);
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
