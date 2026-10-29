<?php

namespace App\Services\LegacyImport;

use UnexpectedValueException;

/**
 * 旧の度数(float の列)を、新しい DB の度数の列(decimal(4,1))に入れる値にする。
 *
 * 旧の列は float なので、2 進で表せない値(1.7 など)が 1.7000000476837158 のように読めることがある。
 * 新の列(小数 1 桁)に合わせて丸める。丸めで値が変わるほど桁の多い値は、黙って丸めずに止める。
 */
final class LegacyVolume
{
    /**
     * @param  string  $context  止めるときの例外に添える、どの表のどの行か(「m_brand の id 1」など)
     *
     * @throws UnexpectedValueException 数でないとき・小数 1 桁に収まらないとき
     */
    public static function fromLegacy(mixed $value, string $context): string
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException("{$context} の volume が数でない");
        }

        $volume = (float) $value;
        $rounded = round($volume, 1);
        if (abs($volume - $rounded) > 0.0001) {
            throw new UnexpectedValueException("{$context} の volume({$volume})が小数 1 桁に収まらない");
        }

        return number_format($rounded, 1, '.', '');
    }
}
