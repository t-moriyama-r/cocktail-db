<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面のカテゴリ木のノードの新規・更新・削除(#121)。旧の DetailEditController@category・middle と
 * FactoryHelper::generateFromRequest のカテゴリ・ミドルの分、insertFamousBrand の置き換え。削除は旧に無い。
 *
 * 閉包表は CategoryTree を通してだけ書き換える(CLAUDE.md の「カテゴリ木」)。
 */
class AdminCategoryWriter
{
    public function __construct(
        private readonly CategoryTree $tree,
        private readonly DescriptionMarkup $markup,
        private readonly AdminImageStore $images,
        private readonly RecipeCounter $counter,
    ) {}

    /**
     * $node(新規なら null)に入力を書いて保存する。
     *
     * @throws ValidationException 同じ親の下に同じ名前のノードがあるとき・親を付け替えると祖先の有名な銘柄が部分木の外になるとき・
     *                             説明の記法が読めないとき
     */
    public function save(?Category $node, SaveCategoryRequest $request): Category
    {
        return DB::transaction(function () use ($node, $request): Category {
            if ($node !== null) {
                // 同じノードを同時に保存したときに、名前・親の確かめと書き換えが食い違わないよう、行をロックして読み直す
                $node = Category::query()->lockForUpdate()->findOrFail($node->id);
            }
            $parent = $request->parentNode();
            if ($parent !== null) {
                // 同じ親の下に同時にノードを足したときに、名前の重なりを見落とさないよう、親の行をロックする
                // (根どうしは親が無いのでロックしない。根を作るのは管理者 1 人の、まれな操作)
                $parent = Category::query()->lockForUpdate()->findOrFail($parent->id);
            }
            $currentParentId = $node?->parent()->first()?->id;
            $moves = $node !== null && $currentParentId !== $parent?->id;

            $name = $request->string('name')->toString();
            $nameSearch = NameNormalizer::normalize($name);
            $this->assertNameIsFreeAmongSiblings($node, $parent, $nameSearch, $moves);

            $videoId = YoutubeInput::videoId($request->youtubeInput());
            $roleBefore = $node?->role;
            $attrs = [
                'name' => $name,
                'name_search' => $nameSearch,
                'name_eng' => $request->string('name_eng')->toString(),
                'description' => $this->markup->toStored($request->string('description')->toString()),
                // 役割は根だけが持つ(SaveCategoryRequest が根でないノードには入れさせない)
                'role' => $parent === null ? $request->nullableString('role') : null,
                'is_major' => $request->boolean('is_major'),
                'is_like' => $request->boolean('is_like'),
                'is_powder' => $request->boolean('is_powder'),
                'youtube_start_seconds' => $request->youtubeStartSeconds($videoId),
                'image_txt' => $request->nullableString('image_txt'),
                'image_url' => $request->nullableString('image_url'),
            ];
            // 有名な銘柄は、選んだときだけ書き換える。選ばず、その場でも作らないときは前の値のまま(旧と同じ)
            if ($request->filled('famous_brand_id')) {
                $attrs['famous_brand_id'] = $request->integer('famous_brand_id');
            }

            if ($node === null) {
                $node = $this->tree->addNode($parent, [...$attrs, 'sort_order' => $this->nextSortOrder($parent)]);
            } else {
                if ($moves) {
                    $this->tree->moveSubtree($node, $parent);
                    $this->assertFamousBrandsStayInSubtrees($node);
                    $attrs['sort_order'] = $this->nextSortOrder($parent, except: $node);
                }
                $node->fill($attrs);
            }

            // 動画を新しく入れたか変えたときは今の時刻、消したときは null(旧と同じ。新着の動画の並びに使う)
            if ($videoId === null) {
                $node->youtube_created_at = null;
            } elseif ($node->youtube_created_at === null || $node->youtube !== $videoId) {
                $node->youtube_created_at = now();
            }
            $node->youtube = $videoId;

            // 画像は最後に置く。ここより前で止まったときに、上げたファイルだけが残らないようにする
            // (ここより後で落ちてロールバックされたときは、AdminImageStore が置いたファイルを消す)
            $node->image = $this->images->update($request->file('image'), $request->boolean('remove_image'), $node->image);
            $node->save();

            if (! $request->filled('famous_brand_id') && $request->filled('brand_name')) {
                $node->famous_brand_id = $this->createFamousBrand($node, $request)->id;
                $node->save();
            }

            // 根の役割を変えると、部分木のノードを材料に使うカクテルの材料の数が変わりうる(「その他」の根に出入りする。#156)
            if ($roleBefore !== $node->role) {
                $this->counter->recountSubtree($node);
            }

            return $node;
        });
    }

    /**
     * 英名が、同じ親の下のほかのノードと同じなら警告を返す(保存は止めない。名前の重なりは 422。#121 の「旧との差分」)。
     *
     * @return list<array{field: string, message: string}>
     */
    public function duplicateWarnings(Category $node): array
    {
        // 親を付け替えた直後でも今の親で比べるよう、リレーションを読み込み直す
        $exists = $this->siblings($node->parent()->first(), $node)->where('name_eng', $node->name_eng)->exists();

        return $exists ? [['field' => 'name_eng', 'message' => '同じ名前が存在しています']] : [];
    }

    /**
     * $node を消す。消せるのは葉で、銘柄・材料・ベース・アフィリエイトから指されていないものだけ。画像のファイルも消す。
     *
     * @throws CategoryNotDeletableException 消せない理由があるとき
     */
    public function delete(Category $node): void
    {
        DB::transaction(function () use ($node): void {
            $node = Category::query()->lockForUpdate()->findOrFail($node->id);
            $this->tree->deleteLeaf($node);
            // 消した行を数えないよう、行を消してから、ほかに指す行があるかを確かめる。ファイルはコミットの後に消える
            $this->images->deleteUnreferenced($node->image);
        });
    }

    /**
     * 同じ親の下(根なら根どうし)に、検索用の名前が同じノードがあれば 422 にする(docs/decisions/admin-screens.md の決まったこと 2)。
     *
     * 名前も親も変えない更新は確かめない。移行した旧のデータには同じ親の下の同じ名前のノードが 1 組あり、
     * 確かめると、名前を変えるまで、その 2 つのどちらも保存できなくなるため(#121 の「旧との差分」)。
     *
     * @throws ValidationException
     */
    private function assertNameIsFreeAmongSiblings(?Category $node, ?Category $parent, string $nameSearch, bool $moves): void
    {
        if ($node !== null && ! $moves && $node->name_search === $nameSearch) {
            return;
        }

        if ($this->siblings($parent, $node)->where('name_search', $nameSearch)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['同じ親の下に、同じ名前のノードがあります'],
            ]);
        }
    }

    /**
     * $parent の子(null なら根)のうち、$except を除いたもの。
     *
     * @return Builder<Category>
     */
    private function siblings(?Category $parent, ?Category $except): Builder
    {
        $idColumn = (new Category)->qualifyColumn('id');

        return Category::query()
            ->whereIn($idColumn, $parent === null
                ? $this->tree->rootIds()
                : CategoryClosure::query()->select('descendant_id')->where('ancestor_id', $parent->id)->where('depth', 1))
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except?->id));
    }

    /**
     * 兄弟の末尾に置く並び順(兄弟の sort_order の最大 + 1。兄弟が無ければ 0)。
     */
    private function nextSortOrder(?Category $parent, ?Category $except = null): int
    {
        $max = $this->siblings($parent, $except)->max('sort_order');

        return is_numeric($max) ? (int) $max + 1 : 0;
    }

    /**
     * 部分木を移した後で、部分木の銘柄を有名な銘柄にしているノードが、その銘柄を自分の部分木に持ち続けているかを確かめる。
     *
     * 有名な銘柄はそのノードの部分木の銘柄から選ぶ(docs/decisions/strength.md の決まったこと 3)。部分木の外の祖先が、
     * 部分木の中の銘柄を有名な銘柄にしていると、移した後はその祖先の部分木の外になる(旧もミドルの親を変えるとき、
     * 元の親のカテゴリの有名な銘柄がそのミドルの銘柄なら拒んでいた)。移す前に戻すため、例外にしてトランザクションを戻す。
     *
     * @throws ValidationException
     */
    private function assertFamousBrandsStayInSubtrees(Category $node): void
    {
        $brandIds = Brand::query()->whereIn('category_id', $this->tree->subtreeIds($node))->pluck('id');

        $outside = Category::query()
            ->whereIn('famous_brand_id', $brandIds)
            ->whereNotExists(fn (QueryBuilder $closure) => $closure
                ->from('category_closures')
                ->join('brands', 'brands.category_id', '=', 'category_closures.descendant_id')
                ->whereColumn('brands.id', 'categories.famous_brand_id')
                ->whereColumn('category_closures.ancestor_id', 'categories.id'))
            ->orderBy('id')
            ->pluck('name');

        if ($outside->isNotEmpty()) {
            throw ValidationException::withMessages([
                'parent_id' => ['このノードの下の銘柄が「'.$outside->implode('」「').'」の有名な銘柄なので、そのノードの外へは移せません'],
            ]);
        }
    }

    /**
     * その場で作る銘柄(旧 insertFamousBrand)を、$node の直下の銘柄として作る。
     *
     * 旧は、作る銘柄の YouTube にノードの YouTube を入れ、リンクの文字と URL を保存しなかった。どちらも旧の不具合なので、
     * 銘柄の欄(brand_*)の値を入れる(#309 の 4。#121 の「旧との差分」)。
     */
    private function createFamousBrand(Category $node, SaveCategoryRequest $request): Brand
    {
        $name = $request->string('brand_name')->toString();
        $videoId = YoutubeInput::videoId($request->youtubeInput('brand_'));

        $brand = new Brand([
            'category_id' => $node->id,
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
            'name_eng' => $request->string('brand_name_eng')->toString(),
            'volume' => $request->float('brand_strength'),
            'description' => $this->markup->toStored($request->string('brand_description')->toString(), 'brand_description'),
            'is_major' => $request->boolean('brand_is_major'),
            'is_like' => $request->boolean('brand_is_like'),
            'youtube' => $videoId,
            'youtube_created_at' => $videoId === null ? null : now(),
            'youtube_start_seconds' => $request->youtubeStartSeconds($videoId, 'brand_'),
            'image_txt' => $request->nullableString('brand_image_txt'),
            'image_url' => $request->nullableString('brand_image_url'),
        ]);
        $brand->image = $this->images->update($request->file('brand_image'), false, null);
        $brand->save();

        return $brand;
    }
}
