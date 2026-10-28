<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveBrandRequest;
use App\Models\Affiliate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\CocktailIngredient;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

/**
 * 管理画面の銘柄の新規・更新(#314)。旧の DetailEditController@brand と FactoryHelper::generateFromRequest の銘柄の分。
 *
 * 所属を変えたときは、同じトランザクションで、その銘柄を使う材料・アフィリエイトのノードを新しい所属にそろえ
 * (docs/schema.md の「材料が銘柄を持つときのノード」)、それらのカクテルの材料の数を数え直す(#156)。
 */
class AdminBrandWriter
{
    public function __construct(
        private readonly DescriptionMarkup $markup,
        private readonly AdminImageStore $images,
        private readonly RecipeCounter $counter,
    ) {}

    /**
     * $brand(新規なら null)に入力を書いて保存する。
     *
     * @throws ValidationException 有名な銘柄を、そのノードの部分木の外へ移そうとしたとき・説明の記法が読めないとき
     */
    public function save(?Brand $brand, SaveBrandRequest $request): Brand
    {
        return DB::transaction(function () use ($brand, $request): Brand {
            if ($brand !== null) {
                // 同じ銘柄を同時に保存したときに、所属の確かめと材料の書き換えが食い違わないよう、行をロックして読み直す
                $brand = Brand::query()->lockForUpdate()->findOrFail($brand->id);
            }
            $brand ??= new Brand;

            $categoryId = $request->integer('category_id');
            $movedFrom = $brand->exists && $brand->category_id !== $categoryId ? $brand->category_id : null;
            if ($movedFrom !== null) {
                $this->assertFamousNodesContain($brand, $categoryId);
            }

            $name = $request->string('name')->toString();
            $videoId = YoutubeInput::videoId($request->youtubeInput());

            $brand->fill([
                'category_id' => $categoryId,
                'name' => $name,
                'name_search' => NameNormalizer::normalize($name),
                'name_eng' => $request->string('name_eng')->toString(),
                'volume' => $request->float('strength'),
                'description' => $this->markup->toStored($request->string('description')->toString()),
                'is_major' => $request->boolean('is_major'),
                'is_like' => $request->boolean('is_like'),
                'youtube_start_seconds' => YoutubeInput::startSeconds(
                    $videoId,
                    $request->nullableInt('youtube_start_hour'),
                    $request->nullableInt('youtube_start_minute'),
                    $request->nullableInt('youtube_start_second'),
                ),
                'image_txt' => $request->nullableString('image_txt'),
                'image_url' => $request->nullableString('image_url'),
            ]);

            // 動画を新しく入れたか変えたときは今の時刻、消したときは null(旧と同じ。新着の動画の並びに使う)
            if ($videoId === null) {
                $brand->youtube_created_at = null;
            } elseif ($brand->youtube_created_at === null || $brand->youtube !== $videoId) {
                $brand->youtube_created_at = now();
            }
            $brand->youtube = $videoId;

            // 画像は最後に置く。ここより前で止まったときに、上げたファイルだけが残らないようにする
            $brand->image = $this->images->update($request->file('image'), $request->boolean('remove_image'), $brand->image);
            $uploaded = $request->file('image') !== null ? $brand->image : null;

            try {
                $brand->save();

                if ($movedFrom !== null) {
                    $this->followCategory($brand);
                }
            } catch (Throwable $e) {
                // ロールバックされるので、置いたファイルを指す行は残らない。孤児にならないよう消す
                if ($uploaded !== null) {
                    $this->images->discard($uploaded);
                }

                throw $e;
            }

            return $brand;
        });
    }

    /**
     * 名前・英名が、ほかの銘柄と同じなら警告を返す(保存は止めない。旧の duplicate_check と同じ比べ方)。
     *
     * 名前は name_search(正規化した名前)で、英名はそのまま比べる。
     *
     * @return list<array{field: string, message: string}>
     */
    public function duplicateWarnings(Brand $brand): array
    {
        $others = Brand::query()->whereKeyNot($brand->id);
        $warnings = [];
        if ((clone $others)->where('name_search', $brand->name_search)->exists()) {
            $warnings[] = ['field' => 'name', 'message' => '同じ名前が存在しています'];
        }
        if ((clone $others)->where('name_eng', $brand->name_eng)->exists()) {
            $warnings[] = ['field' => 'name_eng', 'message' => '同じ名前が存在しています'];
        }

        return $warnings;
    }

    /**
     * $brand を有名な銘柄にしているノードがあれば、新しい所属がそのノードの部分木の中かを確かめる。
     *
     * 有名な銘柄はそのノードの部分木の銘柄から選ぶ(docs/decisions/strength.md の決まったこと 3)ので、部分木の外へは
     * 移させない。旧は所属を少しでも変えると拒んでいたが、部分木の中での移動は許す(#314 の「旧との差分」)。
     *
     * @throws ValidationException
     */
    private function assertFamousNodesContain(Brand $brand, int $categoryId): void
    {
        $outside = Category::query()
            ->where('famous_brand_id', $brand->id)
            ->whereNotIn('id', CategoryClosure::query()->select('ancestor_id')->where('descendant_id', $categoryId))
            ->orderBy('id')
            ->pluck('name');

        if ($outside->isNotEmpty()) {
            throw ValidationException::withMessages([
                'category_id' => ['「'.$outside->implode('」「').'」の有名な銘柄なので、そのノードより下のノードにしか移せません'],
            ]);
        }
    }

    /**
     * $brand を使う材料・アフィリエイトのノードを、$brand の所属にそろえ、その材料のカクテルの材料の数を数え直す。
     */
    private function followCategory(Brand $brand): void
    {
        CocktailIngredient::query()->where('brand_id', $brand->id)->update(['category_id' => $brand->category_id]);
        Affiliate::query()->where('brand_id', $brand->id)->update(['category_id' => $brand->category_id]);

        $cocktailIds = CocktailIngredient::query()
            ->where('brand_id', $brand->id)
            ->distinct()
            ->toBase()
            ->pluck('cocktail_id')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new UnexpectedValueException('カクテルの ID が数でない'))
            ->all();

        $this->counter->recount(array_values($cocktailIds));
    }
}
