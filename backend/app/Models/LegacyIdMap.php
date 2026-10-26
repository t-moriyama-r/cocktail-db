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
 * 保存・削除のときに 2 列で行を特定するようにしている。$primaryKey は片方の列だけなので、
 * find() は旧の表を区別せずに引く。
 */
#[Fillable(['legacy_table', 'legacy_id', 'new_table', 'new_id'])]
class LegacyIdMap extends Model
{
    /** @use HasFactory<LegacyIdMapFactory> */
    use HasFactory;

    private const KEY_COLUMNS = ['legacy_table', 'legacy_id'];

    // 複合の主キー(KEY_COLUMNS)のうち、Eloquent に 1 列として渡せる方
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
        foreach (self::KEY_COLUMNS as $column) {
            $query->where($column, $this->getOriginal($column, $this->getAttribute($column)));
        }

        return $query;
    }
}
