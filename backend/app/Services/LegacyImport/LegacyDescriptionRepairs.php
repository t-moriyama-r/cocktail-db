<?php

namespace App\Services\LegacyImport;

use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧の説明文の、記法の壊れたところを直す(#393。docs/migration-anomalies.md の 14)。
 *
 * 参照の記法を新しい記法に直す前(LegacyDescriptionReferences の前)に、旧の値に当てる。直した後の文は旧の記法のまま。
 *
 * 1. 手での打ち間違い(14 の A〜E): 1 件ずつ壊れ方が違うので、決まりで直さず、表・列・ID と直す前の部分を対にして直す。
 *    直す前の部分が見つからなければ(移行までに旧で書き換えられた・行が消えた)直さず、直さなかった行として出す
 * 2. 消えたカクテルを指す `@c<ID>@`(14 の F): 記法を取り除く
 * 3. それでも記法の外に残った `@`・`＠`(A〜F 以外の壊れた説明文): 取り除いて文字だけを残し、取り除いた行として出す
 *
 * 記法の読み方は旧と同じく、`@`・種類の英字・0 で始まらない数字・`@` の並び。前から読み、`@` は 1 つの記法にしか使わない。
 * 直した数・直さなかった行・取り除いた行は、呼ぶたびに足していく。
 */
final class LegacyDescriptionRepairs
{
    /** 旧の記法 1 つ(LegacyDescriptionReferences と同じ形) */
    private const REFERENCE = '@[cCbBpPmMdD][1-9][0-9]*@';

    /** 旧の記法(種類と ID を取る) */
    private const REFERENCE_PATTERN = '/@([cCbBpPmMdD])([1-9][0-9]*)@/';

    /** 記法の外の `@`・`＠`。記法を飛ばし(`(*SKIP)(*FAIL)`)、残ったものに一致する */
    private const STRAY_AT_PATTERN = '/'.self::REFERENCE.'(*SKIP)(*FAIL)|[@＠]/u';

    /** 種類の英字の無い記法(14 の B)。記法の中の `@` は使わない */
    private const KINDLESS_PATTERN = '/'.self::REFERENCE.'(*SKIP)(*FAIL)|@([1-9][0-9]*)@/';

    private const LEGACY_COCKTAIL = 't_cocktails';

    /** 14 の C で、ID の無い `@p@` が指していた名前(旧の自動リンクの決め打ちの名前) */
    private const GRANULATED_SUGAR = 'グラニュー糖';

    /**
     * 14 の A・C・D・E: [旧の表, 列, ID, 直す前の部分, 直した後]。直した後が null のものは、グラニュー糖のノードの記法にする。
     *
     * @var list<array{string, string, int, string, string|null}>
     */
    private const TYPOS = [
        // A: 2 つ目の記法の @ の打ち間違い
        [self::LEGACY_COCKTAIL, 'description', 153, 'p@@p58@@', '@p58@'],
        // C: ID の無い @p@(当時ノードの無かった、決め打ちの漢字の名前)
        [self::LEGACY_COCKTAIL, 'note', 11, '@p@', null],
        // D: 先頭の @ が全角で、記法が二重
        [self::LEGACY_COCKTAIL, 'note', 238, '＠p@p30@@', '@p30@'],
        // E: 先頭の @ が無い
        [self::LEGACY_COCKTAIL, 'note', 1334, 'p29@', '@p29@'],
    ];

    /** 14 の B: 種類の英字の無い `@<ID>@` を、カクテルの記法 `@c<ID>@` にするカクテルの説明 */
    private const KINDLESS_COCKTAILS = [3620, 3621, 5189, 5629];

    private int $repaired = 0;

    /** @var array<string, true> 直した行(`表.列 ID`) */
    private array $repairedRows = [];

    private int $removedCocktailReferences = 0;

    /** @var list<string> 記法の外の @ を取り除いた行(`表.列 ID`) */
    private array $strayRows = [];

    /**
     * @param  array<int, true>  $legacyCocktailIds  旧のカクテルの ID
     * @param  string|null  $granulatedSugar  グラニュー糖のノードの旧の記法。旧のカテゴリ・ミドルに 1 つだけ無ければ null
     */
    private function __construct(private readonly array $legacyCocktailIds, private readonly ?string $granulatedSugar) {}

    /**
     * 旧 DB から、カクテルの ID と、グラニュー糖のノードを読む。
     */
    public static function load(): self
    {
        $legacyCocktailIds = [];
        foreach (DB::connection('legacy')->table(self::LEGACY_COCKTAIL)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $legacyCocktailIds[(int) $id] = true;
            }
        }

        // 旧の自動リンクはグラニュー糖をカテゴリで引いていた。いまミドルにあっても指せるよう、両方で 1 つだけを探す
        $sugar = [];
        foreach (['p' => LegacyCategoryNodes::LEGACY_CATEGORY, 'm' => LegacyCategoryNodes::LEGACY_MIDDLE] as $kind => $table) {
            foreach (DB::connection('legacy')->table($table)->where('name_search', self::GRANULATED_SUGAR)->pluck('id') as $id) {
                $sugar[] = "@{$kind}".(is_numeric($id) ? (int) $id : 0).'@';
            }
        }

        return new self($legacyCocktailIds, count($sugar) === 1 ? $sugar[0] : null);
    }

    /**
     * 旧の表 $table の ID $id の列 $column の説明文を直す。null・空の文字列はそのまま返す。
     */
    public function repair(string $table, string $column, int $id, ?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $row = self::rowLabel($table, $column, $id);

        $typo = $this->repairTypo($table, $column, $id, $text);
        if ($typo !== null) {
            $text = $typo;
            $this->repaired++;
            $this->repairedRows[$row] = true;
        }

        $text = $this->removeMissingCocktails($text);

        $stripped = self::check(preg_replace(self::STRAY_AT_PATTERN, '', $text), $row);
        if ($stripped !== $text) {
            $this->strayRows[] = $row;
        }

        return $stripped;
    }

    /**
     * A〜E の壊れた記法を直した数(行)。
     */
    public function repaired(): int
    {
        return $this->repaired;
    }

    /**
     * A〜E のうち、直す前の部分が見つからず直さなかった行(`表.列 ID`)。
     *
     * @return list<string>
     */
    public function unrepairedRows(): array
    {
        $rows = [];
        foreach (self::TYPOS as [$table, $column, $id]) {
            $rows[] = self::rowLabel($table, $column, $id);
        }
        foreach (self::KINDLESS_COCKTAILS as $id) {
            $rows[] = self::rowLabel(self::LEGACY_COCKTAIL, 'description', $id);
        }

        return array_values(array_filter($rows, fn (string $row): bool => ! isset($this->repairedRows[$row])));
    }

    /**
     * 消えたカクテルを指す記法を取り除いた数(F)。
     */
    public function removedCocktailReferences(): int
    {
        return $this->removedCocktailReferences;
    }

    /**
     * 記法の外の `@`・`＠` を取り除いた行(`表.列 ID`)。
     *
     * @return list<string>
     */
    public function strayRows(): array
    {
        return $this->strayRows;
    }

    /**
     * A〜E の直し方が決めてある行なら直した文を、直し方が無い・直す前の部分が見つからなければ null を返す。
     */
    private function repairTypo(string $table, string $column, int $id, string $text): ?string
    {
        $row = self::rowLabel($table, $column, $id);
        if ($table === self::LEGACY_COCKTAIL && $column === 'description' && in_array($id, self::KINDLESS_COCKTAILS, true)) {
            return $this->repairKindless($text, $row);
        }

        foreach (self::TYPOS as [$typoTable, $typoColumn, $typoId, $before, $after]) {
            if ($typoTable !== $table || $typoColumn !== $column || $typoId !== $id) {
                continue;
            }
            $after ??= $this->granulatedSugar;
            if ($after === null) {
                return null;
            }

            // 記法の中の文字を使わず、記法の外にちょうど 1 つあるときだけ直す
            $pattern = '/'.self::REFERENCE.'(*SKIP)(*FAIL)|'.preg_quote($before, '/').'/u';
            $count = self::check(preg_match_all($pattern, $text), $row);
            if ($count !== 1) {
                return null;
            }

            return self::check(preg_replace_callback($pattern, fn (): string => $after, $text), $row);
        }

        return null;
    }

    /**
     * 種類の英字の無い `@<ID>@`(B)を、カクテルの記法にする。どれも旧にあるカクテルを指すときだけ直す。
     */
    private function repairKindless(string $text, string $row): ?string
    {
        self::check(preg_match_all(self::KINDLESS_PATTERN, $text, $matches), $row);
        if ($matches[1] === []) {
            return null;
        }
        foreach ($matches[1] as $id) {
            if (strlen($id) > 18 || ! isset($this->legacyCocktailIds[(int) $id])) {
                return null;
            }
        }

        return self::check(preg_replace_callback(self::KINDLESS_PATTERN, fn (array $m): string => "@c{$m[1]}@", $text), $row);
    }

    /**
     * 旧に無いカクテルを指す `@c<ID>@`(F)を取り除く。
     */
    private function removeMissingCocktails(string $text): string
    {
        return preg_replace_callback(self::REFERENCE_PATTERN, function (array $m): string {
            if (strtolower($m[1]) !== 'c' || (strlen($m[2]) <= 18 && isset($this->legacyCocktailIds[(int) $m[2]]))) {
                return $m[0];
            }
            $this->removedCocktailReferences++;

            return '';
        }, $text) ?? throw new UnexpectedValueException('説明文の記法を読めなかった: '.preg_last_error_msg());
    }

    private static function rowLabel(string $table, string $column, int $id): string
    {
        return "{$table}.{$column} {$id}";
    }

    /**
     * 正規表現の結果を確かめる。失敗(UTF-8 でない文字など)なら、どの行かを添えて止める。
     *
     * @template T of int|string
     *
     * @param  T|false|null  $result
     * @return T
     */
    private static function check(int|string|false|null $result, string $row): int|string
    {
        if ($result === null || $result === false) {
            throw new UnexpectedValueException("{$row} の説明文の記法を読めなかった: ".preg_last_error_msg());
        }

        return $result;
    }
}
