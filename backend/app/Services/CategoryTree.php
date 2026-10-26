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
                    throw new InvalidArgumentException("親のノード(id: {$parent->getKey()})がカテゴリ木に入っていない");
                }
            }

            return $node;
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
        return [$node->getKey(), ...$node->descendants()->pluck($node->qualifyColumn('id'))->all()];
    }

    /**
     * 根(閉包表で祖先を持たないノード)を、兄弟の並び順で返す。
     *
     * @return Collection<int, Category>
     */
    public function roots(): Collection
    {
        return $this->inSiblingOrder(Category::query()->whereDoesntHave('ancestors'))->get();
    }

    /**
     * $node のすぐ下の子を、兄弟の並び順で返す。
     *
     * @return Collection<int, Category>
     */
    public function children(Category $node): Collection
    {
        return $this->inSiblingOrder($node->children())->get();
    }

    /**
     * 兄弟の並び順(sort_order、同じなら ID)。sort_order が同じ兄弟もありうるので、ID で順を決めきる。
     *
     * @template TQuery of EloquentBuilder<Category>|BelongsToMany<Category, Category, CategoryClosure>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function inSiblingOrder(EloquentBuilder|BelongsToMany $query): EloquentBuilder|BelongsToMany
    {
        $category = new Category;

        return $query
            ->orderBy($category->qualifyColumn('sort_order'))
            ->orderBy($category->qualifyColumn('id'));
    }
}
