<?php

namespace App\Services\LegacyImport;

/**
 * 件数だけでなく、人が見て扱いを決める旧の行も挙げる移行の部分(#393)。legacy:import が件数の後に出力する。
 */
interface ListsLegacyRows
{
    /**
     * 最後に import() したときの、挙げる行。
     *
     * @return array<string, list<string>> 何の行か => 行(`旧の表.列 ID`)
     */
    public function listedRows(): array;
}
