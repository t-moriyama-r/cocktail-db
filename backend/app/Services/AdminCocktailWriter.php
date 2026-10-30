<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveCocktailRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Services\Recipe\StrengthCalculator;
use App\Services\Recipe\StrengthNotCalculableException;
use App\Support\AmountFormatter;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 管理画面のカクテルの新規・更新(基本情報は #291、材料は #292)。旧の DetailEditController@cocktail と
 * FactoryHelper::generateFromRequest のカクテルの分、RecipeFactory::saveRecipe。
 *
 * 材料は、そのカクテルの材料の行を消してから、送られた配列の順に position を 1 から振って入れる(旧と同じ)。量は
 * AmountFormatter(#159)で整え、材料から作る値(度数 volume・材料の数 recipe_count・材料の組のハッシュ
 * recipe_signature)を同じトランザクションで作り直す。
 */
class AdminCocktailWriter
{
    public function __construct(
        private readonly DescriptionMarkup $markup,
        private readonly AdminImageStore $images,
        private readonly AmountFormatter $amounts,
        private readonly StrengthCalculator $strength,
        private readonly RecipeCounter $counter,
        private readonly RecipeSignature $signature,
    ) {}

    /**
     * $cocktail(新規なら null)に入力を書いて保存する。
     *
     * @throws ValidationException 説明・補足の記法が読めないとき・整えた量が列に入らないとき・材料から度数を計算できないとき
     */
    public function save(?Cocktail $cocktail, SaveCocktailRequest $request): Cocktail
    {
        return DB::transaction(function () use ($cocktail, $request): Cocktail {
            if ($cocktail !== null) {
                // 同じカクテルを同時に保存したときに、動画の日時の判定と画像の差し替えが食い違わないよう、行をロックして読み直す
                $cocktail = Cocktail::query()->lockForUpdate()->findOrFail($cocktail->id);
            }
            $cocktail ??= new Cocktail;

            $ingredients = $this->ingredients($request);

            $name = $request->string('name')->toString();
            $videoId = YoutubeInput::videoId($request->youtubeInput());

            $cocktail->fill([
                'base_category_id' => $request->integer('base_category_id'),
                'name' => $name,
                'name_search' => NameNormalizer::normalize($name),
                'name_eng' => $request->string('name_eng')->toString(),
                'method_id' => $request->integer('method_id'),
                'glass_dictionary_id' => $request->nullableInt('glass_dictionary_id'),
                'ice_id' => $request->nullableInt('ice_id'),
                'rate' => $request->nullableInt('rate'),
                'sweet_degree' => $request->nullableInt('sweet_degree'),
                'is_major' => $request->boolean('is_major'),
                // 下書きかは保存のボタンで決める(docs/decisions/admin-screens.md の 1)。旧は説明の有無で決めていた
                'is_draft' => $request->boolean('is_draft'),
                'description' => $this->markup->toStored($request->nullableString('description')),
                'note' => $this->markup->toStored($request->nullableString('note'), 'note'),
                'youtube_start_seconds' => YoutubeInput::startSeconds(
                    $videoId,
                    $request->nullableInt('youtube_start_hour'),
                    $request->nullableInt('youtube_start_minute'),
                    $request->nullableInt('youtube_start_second'),
                ),
                'image_txt' => $request->nullableString('image_txt'),
                'image_url' => $request->nullableString('image_url'),
                'volume' => $this->strengthOf($ingredients),
            ]);

            // 動画を新しく入れたか変えたときは今の時刻、消したときは null(旧の FactoryHelper と同じ。新着の動画の並びに使う)
            if ($videoId === null) {
                $cocktail->youtube_created_at = null;
            } elseif ($cocktail->youtube_created_at === null || $cocktail->youtube !== $videoId) {
                $cocktail->youtube_created_at = now();
            }
            $cocktail->youtube = $videoId;

            // 画像は最後に置く。ここより前で止まったときに、上げたファイルだけが残らないようにする
            $cocktail->image = $this->images->update($request->file('image'), $request->boolean('remove_image'), $cocktail->image);
            $uploaded = $request->file('image') !== null ? $cocktail->image : null;

            // ロールバックされると、置いたファイルを指す行は残らない。孤児にならないよう消す
            if ($uploaded !== null) {
                DB::afterRollBack(fn () => $this->images->discard($uploaded));
            }

            $cocktail->save();

            // 材料は、いまの行を消してから送られた順に入れ直す(旧の RecipeFactory::saveRecipe と同じ)
            CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->delete();
            foreach ($ingredients as $ingredient) {
                $ingredient->cocktail_id = $cocktail->id;
                $ingredient->save();
            }
            $this->counter->recount([$cocktail->id]);
            $this->signature->rewrite([$cocktail->id]);

            return $cocktail->refresh();
        });
    }

    /**
     * 送られた材料の行を、保存する前の CocktailIngredient にする。position は配列の順に 1 から振り、量は整える。
     * 度数の計算に使うノード(有名な銘柄つき)と銘柄を読み込んでおく。
     *
     * @return list<CocktailIngredient>
     *
     * @throws ValidationException 整えた量が列(20 文字)に入らないとき
     */
    private function ingredients(SaveCocktailRequest $request): array
    {
        /** @var list<array{category_id: int|string, brand_id?: int|string|null, amount: string, is_optional?: bool|int|string}> $rows */
        $rows = $request->validated('ingredients');
        $nodes = Category::query()->with('famousBrand')->findMany(array_column($rows, 'category_id'))->keyBy('id');
        $brandIds = array_filter(array_map(fn (array $row): mixed => $row['brand_id'] ?? null, $rows), fn (mixed $id): bool => $id !== null);
        $brands = Brand::query()->findMany(array_values($brandIds))->keyBy('id');

        $ingredients = [];
        foreach ($rows as $index => $row) {
            $node = $nodes->get((int) $row['category_id']) ?? throw new InvalidArgumentException('検証を通ったノードが無い');
            $brandId = isset($row['brand_id']) ? (int) $row['brand_id'] : null;
            $amount = $this->amounts->format($node, $row['amount']);
            if (mb_strlen($amount) > CocktailIngredient::AMOUNT_MAX_LENGTH) {
                throw ValidationException::withMessages([
                    "ingredients.{$index}.amount" => ['整えた量「'.$amount.'」が '.CocktailIngredient::AMOUNT_MAX_LENGTH.' 文字を超えます。'],
                ]);
            }

            $ingredient = new CocktailIngredient([
                'position' => $index + 1,
                'category_id' => $node->id,
                'brand_id' => $brandId,
                'amount' => $amount,
                'is_optional' => (bool) ($row['is_optional'] ?? false),
            ]);
            $ingredient->setRelation('category', $node);
            $ingredient->setRelation('brand', $brandId === null ? null : $brands->get($brandId));
            $ingredients[] = $ingredient;
        }

        return $ingredients;
    }

    /**
     * 材料の行から度数を計算する。計算できない材料の組は、旧では保存できなかった(0 で割る・分母を数として読めない)ので、
     * 材料の誤りとして返す。
     *
     * @param  list<CocktailIngredient>  $ingredients
     *
     * @throws ValidationException
     */
    private function strengthOf(array $ingredients): float
    {
        try {
            return $this->strength->calculate($ingredients);
        } catch (StrengthNotCalculableException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['ingredients' => [$e->getMessage()]]);
        }
    }
}
