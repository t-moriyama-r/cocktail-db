<?php

namespace App\Support;

use App\Models\Category;
use App\Services\CategoryTree;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 材料の量(cocktail_ingredients.amount)の書式を整え、度数の計算に使う ml の値に直す(#159)。
 *
 * 旧(本番の laravel13)の RecipeCalc::format_amount・convert_amount_int と Rules\Amount と同じ結果にする。
 * 移した量の値と、新で保存する量・計算する度数が食い違わないようにするため。旧の不具合に見える振る舞い
 * (oz を ml に直さない・999ml が適量と同じ値になる、など)も、決めた issue が無いかぎり旧のまま残す。
 */
final class AmountFormatter
{
    /**
     * 適量の材料の、計算用の量。旧と同じ印の値で、度数の計算はこの値の材料を量ではなく適量として別に扱う。
     * 量を決められない cup も、ml や分数と混ぜると値が大きく狂うため、旧は適量として扱っていた。
     */
    public const TO_TASTE = 999.0;

    /**
     * 旧の Rules\Amount::$regex(自動入力で量の書式を確かめていた正規表現)。
     */
    private const PATTERN = '/^(([1-9][0-9]*)[.\/]?[0-9]*(m|ml|oz|da|dr|dash|drop|t|tsp|g|個|片|cup)?|少量|-|適量)$/';

    /**
     * 単位の省略記法と、正式な書き方。旧は上から順に見て、最初に当てはまった 1 つだけを書き足していた。
     * 順番を変えると、複数の記法を含む値(abc など)の結果が旧と変わる。
     *
     * @var list<array{string, string}>
     */
    private const ABBREVIATIONS = [
        ['t', 'tsp'],
        ['m', 'ml'],
        ['da', 'dash'],
        ['dr', 'drop'],
        ['c', 'cup'],
    ];

    /**
     * 計算では 0 ml とみなす単位。どれも、カクテル全体の量に比べて小さいか、液体ではない。
     */
    private const NEGLIGIBLE_UNITS = ['tsp', 'dash', '個', '片', '枚'];

    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * 保存する形に量の書式を整える。
     *
     * 数字だけの量は ml とみなす。ただし「その他」(ガーニッシュなど)の 1 は「1 個」の意味で入力されるため、個を付ける。
     * 「その他」かどうかは 1 のときしか見ない(旧と同じ)。そのため、1 以外の量では、木に入っていないノードでも例外にならない。
     *
     * @throws InvalidArgumentException 量が 1 で、$node が木に入っていないとき
     */
    public function format(Category $node, string $amount): string
    {
        if (is_numeric($amount)) {
            return $amount.((float) $amount === 1.0 && $this->tree->isOther($node) ? '個' : 'ml');
        }
        if ($amount === 'nothing') {
            // 自動入力で量を空にしたいときの書き方
            return '';
        }
        if ($amount === '-') {
            return '適量';
        }

        return self::expandAbbreviation($amount);
    }

    /**
     * 度数の計算に使う量(ml)。書式を整えてから直す。
     *
     * 適量・cup は TO_TASTE を返す。tsp・dash・個など、量として数えない単位は 0。ml と oz は数字の部分をそのまま
     * 使う(旧は oz を ml に直していなかった)。分数(1/2 など)は、グラス 1 杯を 100 ml とみなした量にする。
     * どれにも当てはまらない値(少量・10g など)も 0。
     *
     * @throws InvalidArgumentException 分数の分母が数でない・0 のとき(旧も計算の途中で例外になっていた)。量が 1 で、
     *                                  $node が木に入っていないとき
     */
    public function toMilliliters(Category $node, string $amount): float
    {
        $formatted = $this->format($node, $amount);

        // 1/2tsp のように分数と単位が両方入った値があるため、分数より先に単位を見る
        if (Str::contains($formatted, self::NEGLIGIBLE_UNITS)) {
            return 0.0;
        }
        if (Str::contains($formatted, ['適量', 'cup'])) {
            return self::TO_TASTE;
        }
        if (Str::contains($formatted, ['ml', 'oz'])) {
            // 1/2ml は 1 になる(数字の部分だけを読む。旧と同じ)
            return (float) $formatted;
        }
        if (str_contains($formatted, '/')) {
            return self::fractionToMilliliters($formatted);
        }

        return 0.0;
    }

    /**
     * 旧の正規表現(Rules\Amount)に当てはまる量か。format・toMilliliters はこれに当てはまらない値も受け取るが、
     * 当てはまる値でも分母が 0 などで toMilliliters が例外になるものがある(1/・2/0 など。旧も同じ)。
     */
    public static function isValid(string $amount): bool
    {
        return preg_match(self::PATTERN, $amount) === 1;
    }

    /**
     * 省略記法の単位(t・m・da・dr・c)を、正式な書き方に書き足す。正式な書き方がもう入っていれば書き足さない。
     */
    private static function expandAbbreviation(string $amount): string
    {
        foreach (self::ABBREVIATIONS as [$abbreviation, $unit]) {
            if (str_contains($amount, $abbreviation) && ! str_contains($amount, $unit)) {
                return $amount.substr($unit, strlen($abbreviation));
            }
        }

        return $amount;
    }

    /**
     * 分数の量を、グラス 1 杯(100 ml)に対する割合として ml に直す。
     *
     * 旧は分母を数として読めないと計算の途中で例外になり、カクテルを保存できなかった。黙って 0 などにすると、
     * 度数が旧と違う値のまま保存されるので、ここでも例外にする。
     *
     * @throws InvalidArgumentException 分母が数でない・0 のとき
     */
    private static function fractionToMilliliters(string $amount): float
    {
        $parts = explode('/', $amount);
        $denominator = $parts[1];
        if (! is_numeric($denominator) || (float) $denominator === 0.0) {
            throw new InvalidArgumentException("量「{$amount}」の分母を数として読めません。");
        }

        return (float) $parts[0] * 100 / (float) $denominator;
    }
}
