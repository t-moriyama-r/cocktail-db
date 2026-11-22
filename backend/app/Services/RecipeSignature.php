<?php

namespace App\Services;

use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Support\Facades\DB;
use JsonException;
use UnexpectedValueException;

/**
 * カクテルの材料の組のハッシュ(cocktails.recipe_signature)を作り、列に書く(#82)。
 *
 * 材料の組がまったく同じカクテル(同じレシピのカクテル。旧データに 45 組ある。docs/migration-anomalies.md の 10)を、
 * この列の一致で引く。recipe_signature を書き換えるのはこのクラスだけにし、材料を書き換える所(移行・銘柄の所属の
 * 変更・材料の保存)はどれもここを通す。
 *
 * 材料の組は、材料の行ごとの (ノード・銘柄・量・任意か) を並べたもの。行の並び順(position)は見ない。同じ行が 2 つ
 * あれば 2 つと数える(1 つだけのカクテルとは別のレシピ)。材料の無いカクテルは null にし、どれとも同じにしない。
 */
class RecipeSignature
{
    /**
     * 材料の行の組から、recipe_signature の値を作る。行が無ければ null。
     *
     * @param  iterable<array{category_id: int, brand_id: int|null, amount: string, is_optional: bool}>  $ingredients
     */
    public static function of(iterable $ingredients): ?string
    {
        $rows = [];
        foreach ($ingredients as $ingredient) {
            $rows[] = self::json([
                $ingredient['category_id'],
                $ingredient['brand_id'],
                $ingredient['amount'],
                $ingredient['is_optional'],
            ]);
        }
        if ($rows === []) {
            return null;
        }

        // 行の順によらず同じ値にするため、行を文字列として並べ替えてからまとめる
        sort($rows, SORT_STRING);

        return hash('sha256', self::json($rows));
    }

    /**
     * $cocktailIds のカクテルの recipe_signature を、保存してある材料の行から作り直して書く。材料の無いカクテルは null にする。
     *
     * updated_at は変えない(RecipeCounter::recount と同じく、カクテルの編集ではなく、移行では旧の更新日時を残すため)。
     *
     * @param  list<int>  $cocktailIds
     */
    public function rewrite(array $cocktailIds): void
    {
        $ids = array_values(array_unique($cocktailIds));
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids) {
            $signatures = $this->signaturesByCocktail($ids);

            foreach ($ids as $id) {
                Cocktail::query()->whereKey($id)->toBase()->update(['recipe_signature' => $signatures[$id] ?? null]);
            }
        });
    }

    /**
     * カクテルの ID → 保存してある材料の行から作った recipe_signature。何も書かない。$cocktailIds が null なら、
     * すべてのカクテルについて作る。材料の行が無いカクテルは入らない(recipe_signature は null)。
     *
     * @param  list<int>|null  $cocktailIds
     * @return array<int, string>
     */
    public function signaturesByCocktail(?array $cocktailIds = null): array
    {
        $query = CocktailIngredient::query()->toBase();
        if ($cocktailIds !== null) {
            $query->whereIn('cocktail_id', $cocktailIds);
        }

        $ingredients = [];
        foreach ($query->get(['cocktail_id', 'category_id', 'brand_id', 'amount', 'is_optional']) as $row) {
            $ingredients[self::toInt($row->cocktail_id)][] = [
                'category_id' => self::toInt($row->category_id),
                'brand_id' => $row->brand_id === null ? null : self::toInt($row->brand_id),
                'amount' => is_string($row->amount) ? $row->amount : throw new UnexpectedValueException('量が文字列でない'),
                'is_optional' => (bool) $row->is_optional,
            ];
        }

        $signatures = [];
        foreach ($ingredients as $cocktailId => $rows) {
            $signatures[$cocktailId] = self::of($rows) ?? throw new UnexpectedValueException('材料の行があるのに recipe_signature が作れない');
        }

        return $signatures;
    }

    /**
     * 材料の組を、区切りの文字が量に入っていても取り違えない形の文字列にする。
     *
     * @param  list<mixed>  $value
     */
    private static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('材料の組を文字列にできない', previous: $e);
        }
    }

    /**
     * クエリビルダーで読んだ列の値(ドライバーによって int か数字の文字列)を int にする。
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new UnexpectedValueException('数であるはずの列の値が数でない');
    }
}
