<?php

namespace App\Services;

use App\Enums\DictionaryDeleteBlocker;
use App\Http\Requests\Admin\SaveDictionaryRequest;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Models\Method;
use App\Support\NameNormalizer;
use App\Support\YoutubeInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面の用語辞典の新規・更新・削除(#315)。旧は用語の保存を止めていたので、旧に対応する処理は無い。
 */
class AdminDictionaryWriter
{
    public function __construct(
        private readonly DescriptionMarkup $markup,
        private readonly AdminImageStore $images,
    ) {}

    /**
     * $dictionary(新規なら null)に入力を書いて保存する。
     *
     * @throws ValidationException 説明の記法が読めないとき
     */
    public function save(?Dictionary $dictionary, SaveDictionaryRequest $request): Dictionary
    {
        return DB::transaction(function () use ($dictionary, $request): Dictionary {
            $dictionary ??= new Dictionary;

            $name = $request->string('name')->toString();
            $videoId = YoutubeInput::videoId($request->youtubeInput());

            $dictionary->fill([
                'dictionary_category_id' => $request->integer('dictionary_category_id'),
                'name' => $name,
                'name_search' => NameNormalizer::normalize($name),
                'name_eng' => $request->string('name_eng')->toString(),
                'description' => $this->markup->toStored($request->string('description')->toString()),
                'youtube' => $videoId,
                'youtube_start_seconds' => $request->youtubeStartSeconds($videoId),
                'image_txt' => $request->nullableString('image_txt'),
                'image_url' => $request->nullableString('image_url'),
            ]);

            // 画像は最後に置く。ここより前で止まったときに、上げたファイルだけが残らないようにする
            // (ここより後で落ちてロールバックされたときは、AdminImageStore が置いたファイルを消す)
            $dictionary->image = $this->images->update($request->file('image'), $request->boolean('remove_image'), $dictionary->image);

            $dictionary->save();

            return $dictionary;
        });
    }

    /**
     * $dictionary を消す。画像のファイルも消す。
     *
     * @throws DictionaryNotDeletableException カクテルのグラスに使われている・技法を説明する用語になっているとき
     */
    public function delete(Dictionary $dictionary): void
    {
        DB::transaction(function () use ($dictionary): void {
            // 調べてから消すまでのあいだに、ほかの保存がこの用語を指さないよう、行をロックする
            // (外部キーの確かめは指す先の行を読むので、この用語を指す行を足す保存はロックが解けるまで待つ)
            $dictionary = Dictionary::query()->lockForUpdate()->findOrFail($dictionary->id);

            $blockers = $this->deleteBlockers($dictionary);
            if ($blockers !== []) {
                throw new DictionaryNotDeletableException($blockers);
            }

            $this->images->update(null, true, $dictionary->image);
            $dictionary->delete();
        });
    }

    /**
     * $dictionary を消せない理由。消せるなら空。理由は DictionaryDeleteBlocker の定義の順。
     *
     * @return list<DictionaryDeleteBlocker>
     */
    private function deleteBlockers(Dictionary $dictionary): array
    {
        $found = [
            DictionaryDeleteBlocker::Glass->value => Cocktail::query()->where('glass_dictionary_id', $dictionary->id)->exists(),
            DictionaryDeleteBlocker::Method->value => Method::query()->where('dictionary_id', $dictionary->id)->exists(),
        ];

        return array_values(array_filter(
            DictionaryDeleteBlocker::cases(),
            fn (DictionaryDeleteBlocker $blocker): bool => $found[$blocker->value],
        ));
    }
}
