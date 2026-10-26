<?php

namespace App\Models;

use Database\Factories\LegacyIdMapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 旧 DB の ID と新しい DB の ID の対応(legacy_id_maps)の 1 行。
 *
 * 主キーは (legacy_table, legacy_id) の複合キー。Eloquent は複合の主キーを扱えないため、
 * 保存・削除のときに 2 列で行を特定するようにしている。
 */
#[Fillable(['legacy_table', 'legacy_id', 'new_table', 'new_id'])]
class LegacyIdMap extends Model
{
    /** @use HasFactory<LegacyIdMapFactory> */
    use HasFactory;

    protected $primaryKey = 'legacy_id';

    public $incrementing = false;

    public $timestamps = false;

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
     * 更新・削除の対象を、legacy_id だけでなく legacy_table とあわせて特定する。
     * legacy_id だけでは、旧の別の表の同じ ID の行まで書き換えてしまうため。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('legacy_table', $this->getOriginal('legacy_table', $this->getAttribute('legacy_table')))
            ->where('legacy_id', $this->getOriginal('legacy_id', $this->getAttribute('legacy_id')));
    }
}
