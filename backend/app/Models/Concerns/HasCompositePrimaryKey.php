<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * 複合の主キーを持つ表のモデルで、更新・削除の対象を主キーのすべての列で特定する。
 *
 * Eloquent は複合の主キーを扱えず、$primaryKey に渡せるのは 1 列だけなので、そのままでは更新・削除が
 * その 1 列の値が同じ別の行まで書き換えてしまう。使うモデルは、主キーの列を compositeKeyColumns() で返す。
 */
trait HasCompositePrimaryKey
{
    /**
     * 主キーを構成する列。
     *
     * @return list<string>
     */
    abstract protected function compositeKeyColumns(): array;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->compositeKeyColumns() as $column) {
            $query->where($column, $this->getOriginal($column, $this->getAttribute($column)));
        }

        return $query;
    }
}
