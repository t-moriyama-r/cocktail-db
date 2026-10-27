<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryClosure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * $parent の下(null なら根)にノードを作り、閉包表に自分の行と、すべての祖先からの行を足す。
     *
     * 親の祖先の行は親の閉包表の行から写すので、親が木に入っていない(閉包表に自分の行が無い)と、
     * 祖先とつながらない根として残ってしまう。そのときは例外にして、作ったノードも戻す。
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws InvalidArgumentException 親が保存されていない・木に入っていないとき
     */
    public function addNode(?Category $parent, array $attrs): Category
    {
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
     * @throws InvalidArgumentException 移すノード・親が木に入っていないとき、親が自分か自分の子孫のとき
     */
    public function moveSubtree(Category $node, ?Category $newParent): void
    {
        DB::transaction(function () use ($node, $newParent) {
            if (! $this->isInTree($node)) {
                throw new InvalidArgumentException('移すノードがカテゴリ木に入っていない(保存されていない、または閉包表に自分の行が無い)');
            }
            $subtreeIds = $this->subtreeIds($node);

            if ($newParent !== null) {
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
        $roots = Category::query()->whereDoesntHave('ancestors');
        $this->orderAsSiblings($roots);

        return $roots->get();
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
