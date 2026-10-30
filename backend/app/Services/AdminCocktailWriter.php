<?php

namespace App\Services;

use App\Enums\CocktailDeleteBlocker;
use App\Http\Requests\Admin\SaveCocktailRequest;
use App\Models\Cocktail;
use App\Support\DescriptionParser;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面のカクテルの基本情報の新規・更新(#291)と削除(#130・#579)。旧の DetailEditController@cocktail と
 * FactoryHelper::generateFromRequest のカクテルの分(材料を除く)。
 *
 * 材料と度数(volume)は、材料の保存(#292)が書く。新しく作るカクテルは材料がまだ無いので、度数を 0 にしておく(列が
 * NOT NULL のため)。材料の数(recipe_count)と材料の組のハッシュ(recipe_signature)は、保存のたびに、保存してある材料の行から
 * RecipeCounter・RecipeSignature で作り直す(#130)。
 */
class AdminCocktailWriter
{
    public function __construct(
        private readonly DescriptionMarkup $markup,
        private readonly AdminImageStore $images,
        private readonly RecipeCounter $counter,
        private readonly RecipeSignature $signature,
    ) {}

    /**
     * $cocktail(新規なら null)に入力を書いて保存する。
     *
     * @throws ValidationException 説明・補足の記法が読めないとき
     */
    public function save(?Cocktail $cocktail, SaveCocktailRequest $request): Cocktail
    {
        return DB::transaction(function () use ($cocktail, $request): Cocktail {
            if ($cocktail !== null) {
                // 同じカクテルを同時に保存したときに、動画の日時の判定と画像の差し替えが食い違わないよう、行をロックして読み直す
                $cocktail = Cocktail::query()->lockForUpdate()->findOrFail($cocktail->id);
            }
            if ($cocktail === null) {
                $cocktail = new Cocktail;
                $cocktail->volume = 0.0;
            }

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

            // 数え直しは列だけを書き換えるので、返すモデルも読み直す
            $this->counter->recount([$cocktail->id]);
            $this->signature->rewrite([$cocktail->id]);
            $cocktail->refresh();

            return $cocktail;
        });
    }

    /**
     * $cocktail を消す。材料・別名は外部キーの CASCADE で消える(旧は材料の行を残していた。docs/migration-anomalies.md の 1)。
     *
     * ほかのカクテル・ノード・技法・銘柄・用語の説明文から `@c<ID>@` で参照されているときは消さない(#579)。消すと参照が残り、
     * 画面では黙って取り除かれ(#286)、data:verify(#285 の 8)が失敗するため。
     *
     * @throws CocktailNotDeletableException 説明文から参照されているとき
     */
    public function delete(Cocktail $cocktail): void
    {
        DB::transaction(function () use ($cocktail): void {
            // 同じカクテルの保存・削除と食い違わないよう、行をロックして読み直す。説明文の参照は外部キーではないので、
            // 調べた後に別の保存が参照を足すのは止められない(その参照は data:verify が見つける)
            $cocktail = Cocktail::query()->lockForUpdate()->findOrFail($cocktail->id);

            $reasons = $this->deleteBlockers($cocktail);
            if ($reasons !== []) {
                throw new CocktailNotDeletableException($reasons);
            }

            $cocktail->delete();
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
}
