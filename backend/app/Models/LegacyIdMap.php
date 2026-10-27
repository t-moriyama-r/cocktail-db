<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use Database\Factories\LegacyIdMapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 旧 DB の ID と新しい DB の ID の対応(legacy_id_maps)の 1 行。
 *
 * 主キーは (legacy_table, legacy_id) の複合キー。legacy_id だけでは、旧の別の表の同じ ID の行と区別できない。
 */
#[Fillable(['legacy_table', 'legacy_id', 'new_table', 'new_id'])]
class LegacyIdMap extends Model
{
    use HasCompositePrimaryKey;

    /** @use HasFactory<LegacyIdMapFactory> */
    use HasFactory;

    // 複合の主キー(compositeKeyColumns)のうち、Eloquent に 1 列として渡せる方
    protected $primaryKey = 'legacy_id';

    public $timestamps = false;

    /**
     * 旧の表の ID と新しい表の ID の対応を書く。同じ旧 ID の対応が既にあれば、新しい方で書き直す。
     *
     * @param  array<int, int>  $newIdByLegacyId  旧 ID => 新 ID
     */
    public static function record(string $legacyTable, string $newTable, array $newIdByLegacyId): void
    {
        $rows = [];
        foreach ($newIdByLegacyId as $legacyId => $newId) {
            $rows[] = ['legacy_table' => $legacyTable, 'legacy_id' => $legacyId, 'new_table' => $newTable, 'new_id' => $newId];
        }

        static::query()->upsert($rows, ['legacy_table', 'legacy_id'], ['new_table', 'new_id']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'new_id' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    protected function compositeKeyColumns(): array
    {
        return ['legacy_table', 'legacy_id'];
    }
}
