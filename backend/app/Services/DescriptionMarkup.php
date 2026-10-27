<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryClosure;
use App\Models\Cocktail;
use App\Models\Dictionary;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 説明文(description・note)の中の、ほかのページへの参照の記法(`@n<ID>@` など)を、管理画面の保存と編集のために
 * 変換する(#287。決めたことは docs/decisions/description-markup.md)。
 *
 * 旧の Markup の mark_up(保存のとき)と at_id_append_note(編集で開くとき)を、新しい木の記法(#285)で移したもの。
 * 記法の種類は n(ノード)・c(カクテル)・b(銘柄)・d(用語)。
 */
class DescriptionMarkup
{
    /**
     * 片仮名の並び(旧と同じ。2 文字以上で、先頭は長音・中黒でない)。名前が一致すれば記法に置き換える。
     */
    private const KATAKANA_PATTERN = '/[ァ-ヶ][ァ-ヶ・ー]+/u';

    /**
     * 片仮名でない名前のうち、旧で自動でリンクしていたもの。旧はミドル(MIDDLE_PREG)→ カテゴリ(CATEGORY_PREG)の順に
     * 置き換えていた。ミドルの方を先にしないと「芋焼酎」の「焼酎」・「卵黄」の「卵」が先に取られるので、順を保つ。
     *
     * @var list<string>
     */
    private const KANJI_PATTERNS = [
        '/(芋|麦|米|甲類)焼酎|(赤|白)ワイン|卵黄|卵白|角砂糖/u',
        '/生クリーム|砂糖|紹興酒|梅酒|日本酒|焼酎|番茶|食塩|卵|桃|巨峰|グラニュー糖|食塩|玉ねぎ|乳酸飲料|桜の花|梅|生姜|醤油|酢/u',
    ];

    /**
     * 編集で開くときに名前を添える、ID の記法。
     */
    private const ID_REFERENCE_PATTERN = '/@([nNcCbBdD])([1-9][0-9]*)@/';

    /**
     * 名前で書いた記法(`@c名前@`)。旧と同じく、@ の後の英字 1 文字と、@ を含まない 1 文字以上。
     */
    private const NAMED_REFERENCE_PATTERN = '/@([a-zA-Z])([^@]+)@/';

    /**
     * 閉じていない記法(文末の `@c12` など)。旧と同じく、@ と英字の後が数字のときだけ、記法を書くつもりだったとみなす。
     */
    private const UNCLOSED_REFERENCE_PATTERN = '/@[a-zA-Z]([^@]*)$/';

    /**
     * 編集で開くときに添えた名前(`{!ラム!}`)。
     */
    private const NOTE_PATTERN = '/{![^!]+!}/';

    /**
     * 保存する形にする(旧の mark_up)。添えた名前(`{!…!}`)を取り除き、名前で書いた記法を ID に直し、
     * 名前の一致する語(旧で決め打ちしていた漢字の名前と、片仮名の並び)を記法に置き換える。
     *
     * @param  string  $attribute  422 のエラーを付ける入力の名前
     *
     * @throws ValidationException 記法が閉じていない・記法の種類が無い・名前で書いた記法の名前が見つからないとき(422)
     */
    public function toStored(?string $text, string $attribute = 'description'): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            // 正規表現(/u)が失敗して null を返し、説明文が消えるのを防ぐ
            throw ValidationException::withMessages([$attribute => ['文字コードが UTF-8 ではありません']]);
        }

        $text = $this->resolveNamedReferences($text, $attribute);

        foreach (self::KANJI_PATTERNS as $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $m): string {
                $id = $this->findNodeId($m[0]);

                return $id === null ? $m[0] : "@n{$id}@";
            }, $text);
        }

        return (string) preg_replace_callback(self::KATAKANA_PATTERN, function (array $m): string {
            $name = $m[0];
            foreach (['n', 'c', 'b', 'd'] as $kind) {
                $id = $this->findId($kind, $name);
                if ($id !== null) {
                    return "@{$kind}{$id}@";
                }
            }

            return $name;
        }, $text);
    }

    /**
     * 編集で開く形にする(旧の at_id_append_note)。ID の記法の後に、指す先の名前を `{!名前!}` で添える。
     * 2 段より深いノードは、旧のミドルと同じく「親の名前/名前」にする(同じ名前のノードを見分けるため)。
     * 指す先が無い記法は、旧と同じく取り除く(消したページへのリンクが残っていたもの)。
     */
    public function withNotes(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return (string) preg_replace_callback(self::ID_REFERENCE_PATTERN, function (array $m): string {
            $note = $this->noteFor(strtolower($m[1]), (int) $m[2]);

            return $note === null ? '' : $m[0].'{!'.$note.'!}';
        }, $text);
    }

    /**
     * 添えた名前を取り除き、名前で書いた記法(`@c名前@`)を ID の記法にする(旧の convert_at_id)。
     * ID で書いた記法(`@c12@`)はそのまま残す。記法の大文字・小文字は旧と同じく書いたまま残す。
     *
     * @throws ValidationException
     */
    private function resolveNamedReferences(string $text, string $attribute): string
    {
        if (preg_match(self::UNCLOSED_REFERENCE_PATTERN, $text, $m) === 1 && ctype_digit(substr($m[1], 0, 1))) {
            throw ValidationException::withMessages([$attribute => ['記法が閉じられていません: '.$m[0]]]);
        }

        $text = (string) preg_replace(self::NOTE_PATTERN, '', $text);

        return (string) preg_replace_callback(self::NAMED_REFERENCE_PATTERN, function (array $m) use ($attribute): string {
            [$whole, $command, $name] = $m;
            if (is_numeric($name)) {
                return $whole;
            }
            $kind = strtolower($command);
            if (! in_array($kind, ['n', 'c', 'b', 'd'], true)) {
                throw ValidationException::withMessages([$attribute => ['記法の種類がありません(n・c・b・d のどれか): '.$whole]]);
            }
            $id = $this->findId($kind, $name);
            if ($id === null) {
                throw ValidationException::withMessages([$attribute => ['記法の名前が見つかりません: '.$whole]]);
            }

            return "@{$command}{$id}@";
        }, $text);
    }

    /**
     * 種類 $kind で、名前が $name に一致するものの ID。名前は name_search の形にそろえて比べる。
     */
    private function findId(string $kind, string $name): ?int
    {
        if ($kind === 'n') {
            return $this->findNodeId($name);
        }

        return $this->idOrNull(
            $this->query($kind)->where('name_search', NameNormalizer::normalize($name))->orderBy('id')->value('id'),
        );
    }

    /**
     * 名前が一致するノードの ID。同じ名前のノードが複数あれば、浅いもの(祖先の少ないもの)、同じ深さなら ID の小さいもの。
     * 旧はカテゴリをミドルより先に探していたので、浅い方を先にする。
     */
    private function findNodeId(string $name): ?int
    {
        $depth = CategoryClosure::query()
            ->selectRaw('count(*)')
            ->whereColumn('descendant_id', (new Category)->qualifyColumn('id'));

        return $this->idOrNull(
            Category::query()
                ->where('name_search', NameNormalizer::normalize($name))
                ->orderBy($depth)
                ->orderBy('id')
                ->value('id'),
        );
    }

    private function idOrNull(mixed $id): ?int
    {
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * ID の記法に添える名前。指す先が無ければ null。
     */
    private function noteFor(string $kind, int $id): ?string
    {
        if ($kind !== 'n') {
            $name = $this->query($kind)->whereKey($id)->value('name');

            return is_string($name) ? $name : null;
        }

        $node = Category::query()->find($id);
        if ($node === null) {
            return null;
        }
        if ($node->ancestors()->count() < 2) {
            return $node->name;
        }

        return $node->parent?->name.'/'.$node->name;
    }

    /**
     * ノードでない種類($kind は c・b・d)の表を引く。
     *
     * @return Builder<Cocktail>|Builder<Brand>|Builder<Dictionary>
     */
    private function query(string $kind): Builder
    {
        return match ($kind) {
            'c' => Cocktail::query(),
            'b' => Brand::query(),
            default => Dictionary::query(),
        };
    }
}
