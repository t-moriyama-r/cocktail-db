<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveCocktailRequest;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面のカクテルの基本情報の新規・更新(#291)。旧の DetailEditController@cocktail と
 * FactoryHelper::generateFromRequest のカクテルの分(材料を除く)。
 *
 * 材料と、材料から作る値(度数 volume・材料の数 recipe_count・材料の組のハッシュ recipe_signature)は、材料の保存(#292)が
 * 書く。ここでは触らない。新しく作るカクテルは材料がまだ無いので、度数を 0 にしておく(列が NOT NULL のため)。
 *
 * 別名(#293)は、送ったときだけ、送った並びに置き換える。
 */
class AdminCocktailWriter
{
    public function __construct(
        private readonly DescriptionMarkup $markup,
        private readonly AdminImageStore $images,
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
            $cocktail->image = $this->images->update($request->file('image'), $request->boolean('remove_image'), $cocktail->image);
            $uploaded = $request->file('image') !== null ? $cocktail->image : null;

            // ロールバックされると、置いたファイルを指す行は残らない。孤児にならないよう消す
            if ($uploaded !== null) {
                DB::afterRollBack(fn () => $this->images->discard($uploaded));
            }

            $cocktail->save();

            $aliases = $request->aliases();
            if ($aliases !== null) {
                $this->replaceAliases($cocktail, $aliases);
            }

            return $cocktail;
        });
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
                $where = $other->name_search === $alias->name_search ? '名前' : '別名';
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
