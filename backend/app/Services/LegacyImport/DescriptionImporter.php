<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 移した説明文(description・カクテルの note)の参照の記法を、新しい記法に直す(#285。docs/decisions/description-references.md)。
 * 直す前に、記法の壊れたところを直す(#393。docs/migration-anomalies.md の 14。直し方は LegacyDescriptionRepairs)。
 *
 * 説明文はほかの表の行を指すので、指す先をすべて移してからでないと直せない(カテゴリの説明が、後から作るミドルを指すなど)。
 * そのため、ほかの部分を移した後にまとめて流す。直すのは旧の値からで、新しい DB の値は読まない。何度流しても
 * 同じ結果と同じ件数になり、ほかの部分を移し直して説明文が旧の値に戻っても、流し直せば直る。
 */
final class DescriptionImporter implements LegacyImporter, ListsLegacyRows
{
    /**
     * 説明文を持つ旧の表、移した先の表、列(旧と新で同じ名前)。旧の行は legacy_id_maps で移した先の行を引く。
     *
     * @var list<array{string, string, list<string>}>
     */
    private const TARGETS = [
        ['m_category_kinds', 'categories', ['description']],
        ['m_category', 'categories', ['description']],
        ['m_middle', 'categories', ['description']],
        ['m_method', 'methods', ['description']],
        ['t_dictionary', 'dictionaries', ['description']],
        ['m_brand', 'brands', ['description']],
        ['t_cocktails', 'cocktails', ['description', 'note']],
    ];

    private const UNREPAIRED_ROWS = '直す前の文が違い、壊れた記法(A〜E)を直さなかった説明文';

    private const STRAY_AT_ROWS = '記法の外の @・＠ を取り除いた説明文';

    /** @var array<string, list<string>> 最後に流したときの、行を挙げるもの */
    private array $listedRows = [];

    public function import(): array
    {
        $repairs = LegacyDescriptionRepairs::load();
        $references = new LegacyDescriptionReferences(LegacyCategoryNodes::load());

        DB::transaction(function () use ($repairs, $references) {
            foreach (self::TARGETS as [$legacyTable, $newTable, $columns]) {
                $this->rewriteTable($repairs, $references, $legacyTable, $newTable, $columns);
            }
        });

        $this->listedRows = [
            self::UNREPAIRED_ROWS => $repairs->unrepairedRows(),
            self::STRAY_AT_ROWS => $repairs->strayRows(),
        ];

        return [
            '壊れた記法(A〜E)を直した説明文' => $repairs->repaired(),
            self::UNREPAIRED_ROWS => count($this->listedRows[self::UNREPAIRED_ROWS]),
            '無いカクテルを指し記法を外した参照' => $repairs->removedCocktailReferences(),
            self::STRAY_AT_ROWS => count($this->listedRows[self::STRAY_AT_ROWS]),
            '@p・@m を @n に直した参照' => $references->converted(),
            '対応するノードが無く記法を外した参照' => $references->unmapped(),
            '@C・@B・@D を小文字にした参照' => $references->lowercased(),
        ];
    }

    public function listedRows(): array
    {
        return $this->listedRows;
    }

    /**
     * 旧の表 1 つの説明文を直し、変わった行だけを書く。
     *
     * @param  list<string>  $columns
     */
    private function rewriteTable(LegacyDescriptionRepairs $repairs, LegacyDescriptionReferences $references, string $legacyTable, string $newTable, array $columns): void
    {
        $newIds = LegacyIdMap::newIdsByLegacyId($legacyTable, $newTable);

        foreach (LegacyRows::ordered($legacyTable, 'id') as $row) {
            $legacyId = LegacyRows::int($row, 'id', $legacyTable);
            $newId = $newIds[$legacyId]
                ?? throw new UnexpectedValueException("{$legacyTable} の id {$legacyId} を移した先の {$newTable} の行が無い(説明文は、ほかの部分を移してから直す)");

            $changes = [];
            foreach ($columns as $column) {
                $text = LegacyRows::nullableString($row, $column, $legacyTable);
                $rewritten = $references->rewrite($repairs->repair($legacyTable, $column, $legacyId, $text));
                if ($rewritten !== $text) {
                    $changes[$column] = $rewritten;
                }
            }
            if ($changes !== []) {
                DB::table($newTable)->where('id', $newId)->update($changes);
            }
        }
    }
}
