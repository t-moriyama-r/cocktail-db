<?php

namespace App\Services\LegacySearchCompare;

/**
 * 旧と新の条件検索の結果の、説明できる差分(docs/search-expected-diffs.md の節。#169)。
 *
 * 1 つの値が文書の 1 つの節にあたる。legacy:compare-search は、旧の検索をそのまま再現した結果と、
 * ここに挙げた差分を旧の検索に当てた結果の両方を出し、後者と新の結果が合わないものだけを失敗にする。
 * 節を足したら、ここにも値を足し、LegacySearchReplay で旧の検索への当て方を書く。
 */
enum ExpectedDiff: string
{
    /** [#161] カテゴリを選び、その子のミドルを 1 つも選ばない(`or`・`and`) */
    case CategoryWithoutMiddles = 'category-without-middles';

    /** [#161] あるもの検索の、ミドルを指定した「その他」の材料 */
    case HavingOtherWithMiddle = 'having-other-with-middle';

    /** [#165] あるもの検索で、ID がカクテルの件数を超えるカクテル */
    case HavingIdBeyondCount = 'having-id-beyond-count';

    /** [#165] あるもの検索で、`Middle` が空の配列 */
    case HavingEmptyMiddle = 'having-empty-middle';

    /** [#282] あるもの検索で、カテゴリを選び、その子のミドルを 1 つも選ばない */
    case HavingCategoryWithoutMiddles = 'having-category-without-middles';

    /** [#154] かんたん検索の材料の数 */
    case EasyRecipeCount = 'easy-recipe-count';

    /** [#28] ミドルの親とカテゴリが合わない材料の行 */
    case MiddleParentMismatch = 'middle-parent-mismatch';

    /**
     * docs/search-expected-diffs.md の、この差分の節の見出し(`### ` の後ろ)。
     */
    public function heading(): string
    {
        return match ($this) {
            self::CategoryWithoutMiddles => 'カテゴリを選び、その子のミドルを 1 つも選ばない(`or`・`and`)',
            self::HavingOtherWithMiddle => 'あるもの検索の、ミドルを指定した「その他」の材料',
            self::HavingIdBeyondCount => 'あるもの検索で、ID がカクテルの件数を超えるカクテル',
            self::HavingEmptyMiddle => 'あるもの検索で、`Middle` が空の配列',
            self::HavingCategoryWithoutMiddles => 'あるもの検索で、カテゴリを選び、その子のミドルを 1 つも選ばない',
            self::EasyRecipeCount => 'かんたん検索の材料の数',
            self::MiddleParentMismatch => 'ミドルの親とカテゴリが合わない材料の行',
        };
    }

    /**
     * 節を置いた issue の番号(docs/search-expected-diffs.md の `## [#<番号>] …` の番号)。
     */
    public function issue(): int
    {
        return match ($this) {
            self::CategoryWithoutMiddles, self::HavingOtherWithMiddle => 161,
            self::HavingIdBeyondCount, self::HavingEmptyMiddle => 165,
            self::HavingCategoryWithoutMiddles => 282,
            self::EasyRecipeCount => 154,
            self::MiddleParentMismatch => 28,
        };
    }

    /**
     * 出力に出す名前。
     */
    public function label(): string
    {
        return "#{$this->issue()} {$this->heading()}";
    }

    /**
     * $mode の検索に当てる差分。
     *
     * @return list<self>
     */
    public static function forMode(string $mode): array
    {
        return array_values(array_filter(self::cases(), fn (self $diff): bool => match ($diff) {
            self::CategoryWithoutMiddles => $mode !== SearchCase::HAVING,
            self::HavingOtherWithMiddle, self::HavingIdBeyondCount, self::HavingEmptyMiddle,
            self::HavingCategoryWithoutMiddles => $mode === SearchCase::HAVING,
            self::EasyRecipeCount, self::MiddleParentMismatch => true,
        }));
    }
}
