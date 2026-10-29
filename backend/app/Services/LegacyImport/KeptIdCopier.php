<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
use Illuminate\Support\Facades\DB;

/**
 * 旧の表の行を、ID を変えずに新しい表へ写す。
 *
 * 旧 ID のまま入れる表(技法・氷・用語辞典・銘柄・ユーザーなど)は、ほかの表や URL が旧 ID のまま指すので
 * ID を振り直せない。それでも legacy_id_maps には同じ値の対応を入れ、旧 ID から新 ID を引く処理を表ごとに
 * 分けずに済むようにする(docs/schema.md の ID の方針)。
 *
 * 2 回目は消して入れ直さずに上書きする。移した後は、ほかの表が外部キー(RESTRICT)でこの行を指すため。
 */
final class KeptIdCopier
{
    /** MariaDB のプリペアドステートメント 1 文に置けるプレースホルダの数の上限 */
    private const MAX_PLACEHOLDERS = 65535;

    /**
     * 旧の表の行を ID の順に読み、$toNewRow で新しい表の行にして書く。
     *
     * @param  callable(array<mixed>): array<string, mixed>  $toNewRow  旧の行 → 新しい表の行(id を含む)
     * @return list<int> 写した行の ID
     */
    public static function copy(string $legacyTable, string $newTable, callable $toNewRow): array
    {
        $rows = array_map($toNewRow(...), LegacyRows::ordered($legacyTable, 'id'));
        self::upsert($newTable, $rows, 'id');

        $newIdByLegacyId = [];
        foreach ($rows as $row) {
            $id = LegacyRows::int($row, 'id', $legacyTable);
            $newIdByLegacyId[$id] = $id;
        }
        LegacyIdMap::record($legacyTable, $newTable, $newIdByLegacyId);

        return array_keys($newIdByLegacyId);
    }

    /**
     * 同じキーの行があれば、キー以外の列を旧の値で書き直す。
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  'id'|'name'  $uniqueBy
     */
    public static function upsert(string $table, array $rows, string $uniqueBy): void
    {
        if ($rows === []) {
            return;
        }

        // 更新する列を空にすると、upsert は重複を無視せずにただの INSERT になるので、キー自身を書き直す
        $columns = array_values(array_diff(array_keys($rows[0]), [$uniqueBy]));
        // 行 × 列がプレースホルダの上限を超えないように分けて書く
        $chunkSize = max(1, intdiv(self::MAX_PLACEHOLDERS, count($rows[0])));
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->upsert($chunk, [$uniqueBy], $columns === [] ? [$uniqueBy] : $columns);
        }
    }
}
