<?php

namespace App\Services\Recipe;

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use App\Support\AmountFormatter;
use InvalidArgumentException;
use LogicException;

/**
 * カクテルの度数(cocktails.volume)を材料から計算する(docs/decisions/strength.md。#158・#160)。
 *
 * 旧(本番の laravel13)の RecipeCalc::calcStrength と同じ結果にする。移した度数(旧の値)と、新で保存し直した度数が
 * 計算の違いで食い違わないようにするため。足し算・掛け算の順も旧のまま残す(順を変えると、小数 1 桁に丸める境目で
 * 結果が変わりうる)。
 */
final class StrengthCalculator
{
    /**
     * calculate に渡す材料の行に読み込んでおくリレーション(Cocktail から見た名前)。
     */
    public const RELATIONS = ['ingredients.category.famousBrand', 'ingredients.brand'];

    public function __construct(private readonly AmountFormatter $amounts) {}

    /**
     * 材料の行から度数(%)を計算する。保存する前の行も渡せるよう、行の並び(レシピに書いた順)のまま受ける。
     *
     * 任意の材料や「その他」の材料も計算に入れる(旧と同じ。材料の数を数える RecipeCounter とは対象が違う)。
     * 適量の材料は、ほかの材料の 2 倍の量で割る(3 倍に薄める)ものとして、適量の材料の度数の平均で足す。
     * 量のある材料が無く適量だけのときは、適量の材料の度数の平均を丸めずに返す(旧と同じ。保存する列が小数 1 桁なので、
     * 保存するときに丸まる)。
     *
     * @param  iterable<CocktailIngredient>  $ingredients  category・category.famousBrand・brand を読み込んだもの
     *
     * @throws StrengthNotCalculableException 量のある材料も適量の材料も無いとき(tsp・dash・個だけ、材料が無い)。
     *                                        旧も 0 で割って保存できなかった。0% にすると、旧と違う度数を黙って保存する
     * @throws InvalidArgumentException 量を ml に直せないとき(AmountFormatter::toMilliliters)
     */
    public function calculate(iterable $ingredients): float
    {
        $allVolume = 0.0;
        $alcoholVolume = 0.0;
        $toTasteStrengths = [];
        foreach ($ingredients as $ingredient) {
            // 材料のノードは外部キー(NOT NULL・RESTRICT)で必ずある
            $node = $ingredient->category
                ?? throw new LogicException("カクテル {$ingredient->cocktail_id} の材料 {$ingredient->position} のノードが無い");
            $strength = self::ingredientStrength($ingredient, $node);
            // 旧は銘柄の所属のカテゴリで量を直していた。材料のノードは銘柄の所属と同じか、その祖先
            // (docs/schema.md の「材料が銘柄を持つときのノード」)で根が同じなので、量の直し方(「その他」の判定)は変わらない
            $milliliters = $this->amounts->toMilliliters($node, $ingredient->amount);
            if ($milliliters === AmountFormatter::TO_TASTE) {
                $toTasteStrengths[] = $strength;

                continue;
            }
            $allVolume += $milliliters;
            $alcoholVolume += $milliliters * $strength;
        }

        if ($toTasteStrengths !== []) {
            $alcoholVolume += array_sum($toTasteStrengths) / count($toTasteStrengths) * $allVolume * 2;
            $allVolume += $allVolume * 2;
        }
        if ($allVolume === 0.0) {
            if ($toTasteStrengths === []) {
                throw new StrengthNotCalculableException('量のある材料も適量の材料も無いので、度数を計算できません。');
            }

            return array_sum($toTasteStrengths) / count($toTasteStrengths);
        }

        return round($alcoholVolume / $allVolume, 1);
    }

    /**
     * 保存してあるカクテルの材料から度数を計算する。
     *
     * @throws StrengthNotCalculableException calculate と同じ
     * @throws InvalidArgumentException calculate と同じ
     */
    public function forCocktail(Cocktail $cocktail): float
    {
        $cocktail->loadMissing(self::RELATIONS);

        return $this->calculate($cocktail->ingredients);
    }

    /**
     * 材料 1 行の度数(%)。
     *
     * 銘柄を決めない材料は、材料のノードの代表として「有名な銘柄」の度数を使う。有名な銘柄の無いノードは、祖先に
     * 有名な銘柄があっても 0% にする(#158 で決めた案 X。祖先の銘柄を使うと、祖先の銘柄を変えたときに配下の
     * カクテルの正しい度数が変わり、どの銘柄で計算したかも画面から見えにくくなる)。
     */
    private static function ingredientStrength(CocktailIngredient $ingredient, Category $node): float
    {
        $brand = $ingredient->brand ?? $node->famousBrand;

        return $brand === null ? 0.0 : (float) $brand->volume;
    }
}
