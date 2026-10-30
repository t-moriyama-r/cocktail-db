<?php

namespace App\Services\Search;

use App\Models\Category;
use App\Models\Cocktail;
use App\Support\AdminListName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use stdClass;
use UnexpectedValueException;

/**
 * 条件検索の結果を、結果の表の列で並べ替える(#167。docs/search.md の「並べ替え(画面)」)。
 *
 * 旧は全件を受け取り、画面(旧 Results/Tables/Cocktails.vue)の JS で並べ替えていた。新はページングするので、
 * 同じ比べ方をサーバーで行う。名前・ベースはカタカナをひらがなに(旧の Helper.katakanaToHiragana)、英名は小文字に
 * そろえ、JS の文字列の比較と同じく UTF-16 の符号の単位で比べる。SQL の照合順序ではこの並びにならないので、当たった
 * カクテルの並べ替えに使う列だけを読み、PHP で並べる(管理画面の一覧の #289 と同じやり方)。
 *
 * 値の無いもの(甘さ・評価・英名が null、動画が無い)は、向きによらずいつも最後にする。同じ値のものは ID の昇順。
 *
 * 当たったカクテル(多いと約 5,700 件)はモデルにせず、行のまま読む。モデルにすると 1 件ごとに組み立てる分だけ、
 * 移行後と同じ規模のデータで全件が当たる検索 1 回が数十〜100ms ほど遅くなった(#96)。
 */
final class CocktailSearchSort
{
    /**
     * 並べ替えに使える列。id は既定の並び(docs/decisions/search-screen.md の 5)。ほかは旧の表の列で、名前は
     * 旧の画面の並べ替えの鍵と、応答の項目の名前に合わせる。
     */
    public const SORTS = [
        'id', 'method', 'name', 'name_eng', 'base', 'recipe_count', 'sweet_degree', 'rate', 'strength', 'youtube',
    ];

    /**
     * order を省いたときに降順にする列。旧の画面で、見出しを最初に押したときに降順になった列。
     */
    private const DESC_FIRST = ['sweet_degree', 'rate', 'strength', 'youtube'];

    /**
     * 列 $sort で order を省いたときの向き(旧の画面で、見出しを最初に押したときの向き)。
     */
    public static function defaultOrder(string $sort): string
    {
        return in_array($sort, self::DESC_FIRST, true) ? 'desc' : 'asc';
    }

    /**
     * $cocktails に当たるカクテルの ID を、列 $sort の向き $order で並べて返す。
     *
     * @param  Builder<Cocktail>  $cocktails
     * @return list<int>
     */
    public function sortedIds(Builder $cocktails, string $sort, string $order): array
    {
        $rows = $cocktails
            ->select(['id', 'name', 'name_eng', 'base_category_id', 'method_id', 'recipe_count', 'sweet_degree', 'rate', 'volume', 'youtube', 'updated_at'])
            ->toBase()
            ->get();
        // ベースの名前は、ベースで並べるときだけ読む。ノードは多くても数百なので、まとめて 1 本で引く
        $baseNames = $sort === 'base'
            ? Category::query()->whereIn('id', $rows->pluck('base_category_id')->unique()->values())->pluck('name', 'id')->all()
            : [];

        $keyed = [];
        foreach ($rows as $row) {
            $id = self::toInt($row->id);
            $keyed[] = ['id' => $id, 'key' => self::key($sort, $row, $id, $baseNames)];
        }

        $direction = $order === 'desc' ? -1 : 1;
        usort($keyed, function (array $a, array $b) use ($direction): int {
            $byKey = self::compare($a['key'], $b['key'], $direction);

            return $byKey !== 0 ? $byKey : $a['id'] <=> $b['id'];
        });

        return array_map(fn (array $row): int => $row['id'], $keyed);
    }

    /**
     * 並べ替えの鍵。null は値の無いもの。文字列は UTF-16BE にして、バイト列の比較で UTF-16 の符号の単位の順にする。
     *
     * @param  array<int|string, mixed>  $baseNames  ベースのノードの ID → 名前
     */
    private static function key(string $sort, stdClass $row, int $id, array $baseNames): int|float|string|null
    {
        return match ($sort) {
            'id' => $id,
            'method' => self::toInt($row->method_id),
            'name' => self::utf16(AdminListName::fold(self::toString($row->name))),
            'name_eng' => $row->name_eng === null ? null : self::utf16(mb_strtolower(self::toString($row->name_eng))),
            'base' => self::utf16(AdminListName::fold(self::baseName($id, $row->base_category_id, $baseNames))),
            'recipe_count' => self::toInt($row->recipe_count),
            'sweet_degree' => $row->sweet_degree === null ? null : self::toInt($row->sweet_degree),
            'rate' => $row->rate === null ? null : self::toInt($row->rate),
            'strength' => Cocktail::strengthOf($row->volume),
            // 動画のあるものどうしは、カクテルの更新日時で比べる(旧と同じ)
            'youtube' => $row->youtube === null ? null : self::timestamp($row->updated_at),
            default => throw new InvalidArgumentException("並べ替えられない列: {$sort}"),
        };
    }

    /**
     * 鍵を比べる。値の無いものは向きによらず後ろにする。
     */
    private static function compare(int|float|string|null $a, int|float|string|null $b, int $direction): int
    {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }

        $order = is_string($a) && is_string($b) ? strcmp($a, $b) : $a <=> $b;

        return ($order <=> 0) * $direction;
    }

    /**
     * ベースのノードの名前。外部キー(NOT NULL・RESTRICT)で必ずあるので、無ければデータの壊れとして例外にする。
     *
     * @param  array<int|string, mixed>  $baseNames
     */
    private static function baseName(int $id, mixed $baseCategoryId, array $baseNames): string
    {
        $name = $baseNames[self::toInt($baseCategoryId)] ?? throw new LogicException("カクテル {$id} のベースが無い");

        return self::toString($name);
    }

    /**
     * 更新日時の秒。モデルの updated_at と同じく、アプリの時間帯で読む。値が無ければ 0(モデルの null と同じ扱い)。
     */
    private static function timestamp(mixed $value): int
    {
        return $value === null ? 0 : Carbon::parse(self::toString($value))->getTimestamp();
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new UnexpectedValueException('数でない値: '.get_debug_type($value));
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new UnexpectedValueException('文字列でない値: '.get_debug_type($value));
    }

    private static function utf16(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }
}
