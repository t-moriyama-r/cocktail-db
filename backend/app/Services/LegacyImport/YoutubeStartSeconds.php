<?php

namespace App\Services\LegacyImport;

/**
 * 旧の YouTube の開始位置(y_hour・y_minute・y_second の 3 列)を、新しい DB の秒の 1 列にする
 * (docs/schema.md の「YouTube の開始位置」)。
 */
final class YoutubeStartSeconds
{
    /**
     * 旧は開始位置を決めていない行で 3 列とも null にしていたので、そのときは null のままにする。
     * 旧のデータは時の列が null のまま分・秒だけに値を持つので、どれかに値があれば null を 0 として足す。
     */
    public static function fromLegacy(mixed $hour, mixed $minute, mixed $second): ?int
    {
        if ($hour === null && $minute === null && $second === null) {
            return null;
        }

        return self::toInt($hour) * 3600 + self::toInt($minute) * 60 + self::toInt($second);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
