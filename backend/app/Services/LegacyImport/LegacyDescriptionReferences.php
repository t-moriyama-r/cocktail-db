<?php

namespace App\Services\LegacyImport;

use UnexpectedValueException;

/**
 * 旧の説明文の参照の記法を、新しい記法に直す(#285。決めたことは docs/decisions/description-references.md)。
 *
 * 旧の `@p<ID>@`(カテゴリ)・`@m<ID>@`(ミドル)は旧の ID を指すので、カテゴリ木を移した先のノードの ID にして
 * `@n<ノードの ID>@` にする。対応するノードが無ければ、記法を取り除いて前後の文字だけを残す。
 * `@c`(カクテル)・`@b`(銘柄)・`@d`(用語)は ID を変えずに移すので、ID はそのまま残す。
 * 大文字の記法(`@P` など)は、旧の generateDiscriptionArray と同じ意味として小文字にそろえる。
 *
 * 直した数・直せなかった数・小文字にそろえた数を、呼ぶたびに足していく。
 */
final class LegacyDescriptionReferences
{
    /**
     * 旧の ID の記法。種類は旧の command と同じ c・b・p・m・d(大文字も同じ意味)、ID は 0 で始まらない数字。
     */
    private const PATTERN = '/@([cCbBpPmMdD])([1-9][0-9]*)@/';

    /** ノードを指す旧の記法の種類 => 旧の表 */
    private const NODE_KINDS = [
        'p' => LegacyCategoryNodes::LEGACY_CATEGORY,
        'm' => LegacyCategoryNodes::LEGACY_MIDDLE,
    ];

    private int $converted = 0;

    private int $unmapped = 0;

    private int $lowercased = 0;

    public function __construct(private readonly LegacyCategoryNodes $nodes) {}

    /**
     * 説明文の参照の記法を直す。null・空の文字列はそのまま返す。
     */
    public function rewrite(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_replace_callback(self::PATTERN, function (array $m): string {
            $kind = strtolower($m[1]);
            $legacyTable = self::NODE_KINDS[$kind] ?? null;
            if ($legacyTable === null) {
                if ($kind !== $m[1]) {
                    $this->lowercased++;
                }

                return "@{$kind}{$m[2]}@";
            }

            // 18 桁を超える ID は int に収まらず、対応も無いので、直せなかったものに数える
            $nodeId = strlen($m[2]) > 18 ? null : $this->nodes->find($legacyTable, (int) $m[2]);
            if ($nodeId === null) {
                $this->unmapped++;

                return '';
            }
            $this->converted++;

            return "@n{$nodeId}@";
        }, $text) ?? throw new UnexpectedValueException('説明文の参照の記法を直せなかった: '.preg_last_error_msg());
    }

    /**
     * `@p`・`@m` を `@n` に直した数。
     */
    public function converted(): int
    {
        return $this->converted;
    }

    /**
     * `@p`・`@m` のうち、対応するノードが無く記法を取り除いた数。
     */
    public function unmapped(): int
    {
        return $this->unmapped;
    }

    /**
     * 大文字の `@C`・`@B`・`@D` を小文字にそろえた数。
     */
    public function lowercased(): int
    {
        return $this->lowercased;
    }
}
