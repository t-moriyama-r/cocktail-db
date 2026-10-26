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
 * $primaryKey は片方の列だけなので、find() は旧の表を区別せずに引く。
 */
#[Fillable(['legacy_table', 'legacy_id', 'new_table', 'new_id'])]
class LegacyIdMap extends Model
{
    use HasCompositePrimaryKey;

    /** @use HasFactory<LegacyIdMapFactory> */
    use HasFactory;

    // 複合の主キー(compositeKeyColumns)のうち、Eloquent に 1 列として渡せる方
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
     * @return list<string>
     */
    protected function compositeKeyColumns(): array
    {
        return ['legacy_table', 'legacy_id'];
    }
}
