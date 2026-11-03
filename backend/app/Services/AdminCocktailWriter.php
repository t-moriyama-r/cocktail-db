<?php

namespace App\Services;

use App\Enums\CocktailDeleteBlocker;
use App\Http\Requests\Admin\SaveCocktailRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
use App\Services\Recipe\StrengthCalculator;
use App\Services\Recipe\StrengthNotCalculableException;
use App\Support\AmountFormatter;
use App\Support\DescriptionParser;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 管理画面のカクテルの新規・更新(基本情報は #291、材料は #292)と削除(#130・#579)。旧の DetailEditController@cocktail と
 * FactoryHelper::generateFromRequest のカクテルの分、RecipeFactory::saveRecipe。
 *
 * 材料は、そのカクテルの材料の行を消してから、送られた配列の順に position を 1 から振って入れる(旧と同じ)。量は
 * AmountFormatter(#159)で整え、材料から作る値(度数 volume・材料の数 recipe_count・材料の組のハッシュ
 * recipe_signature)を同じトランザクションで作り直す。
 *
 * 別名(#293)は、送ったときだけ、送った並びに置き換える。
 *
 * 削除(#130)では、画像のファイルも消す(#578)。
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
            // (ここより後で落ちてロールバックされたときは、AdminImageStore が置いたファイルを消す)
            $cocktail->image = $this->images->update($request->file('image'), $request->boolean('remove_image'), $cocktail->image);

            $cocktail->save();

            // 材料は、いまの行を消してから送られた順に入れ直す(旧の RecipeFactory::saveRecipe と同じ)
            CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->delete();
            foreach ($ingredients as $ingredient) {
                $ingredient->cocktail_id = $cocktail->id;
                $ingredient->save();
            }

            $aliases = $request->aliases();
            if ($aliases !== null) {
                $this->replaceAliases($cocktail, $aliases);
            }

            // 数え直しは列だけを書き換えるので、返すモデルも読み直す
            $this->counter->recount([$cocktail->id]);
            $this->signature->rewrite([$cocktail->id]);
            $cocktail->refresh();

            return $cocktail;
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

    /**
     * $cocktail を消す。材料・別名は外部キーの CASCADE で消える(旧は材料の行を残していた。docs/migration-anomalies.md の 1)。
     * 画像のファイルも消すが、ほかの行が同じファイルを指していれば残す(#578)。
     *
     * ほかのカクテル・ノード・技法・銘柄・用語の説明文から `@c<ID>@` で参照されているときは消さない(#579)。消すと参照が残り、
     * 画面では黙って取り除かれ(#286)、data:verify(#285 の 8)が失敗するため。
     *
     * @throws CocktailNotDeletableException 説明文から参照されているとき
     */
    public function delete(Cocktail $cocktail): void
    {
        DB::transaction(function () use ($cocktail): void {
            // 同じカクテルの保存・削除や画像の差し替えと食い違わないよう、行をロックして読み直す。説明文の参照は外部キーではないので、
            // 調べた後に別の保存が参照を足すのは止められない(その参照は data:verify が見つける)
            $cocktail = Cocktail::query()->lockForUpdate()->findOrFail($cocktail->id);

            $reasons = $this->deleteBlockers($cocktail);
            if ($reasons !== []) {
                throw new CocktailNotDeletableException($reasons);
            }

            $cocktail->delete();
            // 消した行を数えないよう、行を消してから、ほかに指す行があるかを確かめる。ファイルはコミットの後に消える
            $this->images->deleteUnreferenced($cocktail->image);
        });
    }

    /**
     * $cocktail を消せない理由と、理由ごとの参照している行。消せるなら空。理由は CocktailDeleteBlocker の定義の順、行は ID の順。
     * 自分の説明・補足からの参照は、一緒に消えるので数えない。
     *
     * @return list<array{blocker: CocktailDeleteBlocker, referrers: non-empty-list<array{id: int, name: string}>}>
     */
    private function deleteBlockers(Cocktail $cocktail): array
    {
        $id = $cocktail->id;
        $reasons = [];
        foreach (CocktailDeleteBlocker::cases() as $blocker) {
            [$table, $columns] = $blocker->source();
            // LIKE は候補を絞るだけ(照合順序で大文字の @C も拾う)。参照かどうかは、画面に出すとき(DescriptionParser)と同じ読み方で決める
            $rows = DB::table($table)
                ->where(function (QueryBuilder $query) use ($columns, $id): void {
                    foreach ($columns as $column) {
                        $query->orWhere($column, 'like', "%@c{$id}@%");
                    }
                })
                ->when($blocker === CocktailDeleteBlocker::Cocktail, fn (QueryBuilder $query) => $query->where('id', '<>', $id))
                ->orderBy('id')
                ->get(['id', 'name', ...$columns]);

            $referrers = [];
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    if (self::references($row->{$column}, $id)) {
                        $referrers[] = ['id' => is_numeric($row->id) ? (int) $row->id : 0, 'name' => is_string($row->name) ? $row->name : ''];
                        break;
                    }
                }
            }
            if ($referrers !== []) {
                $reasons[] = ['blocker' => $blocker, 'referrers' => $referrers];
            }
        }

        return $reasons;
    }

    /**
     * 説明文 $text に、カクテル $id を指す参照(`@c<ID>@`・`@C<ID>@`)があるか。
     */
    private static function references(mixed $text, int $id): bool
    {
        if (! is_string($text)) {
            return false;
        }
        preg_match_all(DescriptionParser::REFERENCE_PATTERN, $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (strtolower($m[1]) === 'c' && (int) $m[2] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * 別名の検索用の名前が、ほかのカクテルの名前か別名と同じものを返す(#293)。保存は止めない。
     *
     * 別名の並び(登録した順)ごとに、同じになったカクテルを ID の順に 1 件ずつ並べる。名前と別名の両方で同じなら、名前の方を返す。
     *
     * @return list<array{field: string, message: string, cocktail_id: int, cocktail_name: string}>
     */
    public function aliasWarnings(Cocktail $cocktail): array
    {
        $warnings = [];
        foreach ($cocktail->aliases()->get() as $index => $alias) {
            $others = Cocktail::query()
                ->whereKeyNot($cocktail->id)
                ->where(fn ($q) => $q
                    ->where('name_search', $alias->name_search)
                    ->orWhereHas('aliases', fn ($a) => $a->where('name_search', $alias->name_search)))
                ->orderBy('id')
                ->get(['id', 'name', 'name_search']);

            foreach ($others as $other) {
                $where = mb_strtolower($other->name_search) === mb_strtolower($alias->name_search) ? '名前' : '別名';
                $warnings[] = [
                    'field' => "aliases.{$index}",
                    'message' => "「{$other->name}」の{$where}と同じです",
                    /** @var int */
                    'cocktail_id' => $other->id,
                    'cocktail_name' => $other->name,
                ];
            }
        }

        return $warnings;
    }

    /**
     * $cocktail の別名を $names に置き換える。登録した順(ID の順)が送った並びになるよう、入れ直す。
     *
     * @param  list<string>  $names
     */
    private function replaceAliases(Cocktail $cocktail, array $names): void
    {
        CocktailAlias::query()->where('cocktail_id', $cocktail->id)->delete();
        foreach ($names as $name) {
            CocktailAlias::query()->create([
                'cocktail_id' => $cocktail->id,
                'name' => $name,
                'name_search' => NameNormalizer::normalize($name),
            ]);
        }
    }
}
