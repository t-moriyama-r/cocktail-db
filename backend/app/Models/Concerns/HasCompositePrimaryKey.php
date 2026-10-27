<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * 複合の主キーを持つ表のモデルで、1 行を引く・更新する・削除する対象を、主キーのすべての列で特定する。
 *
 * Eloquent は複合の主キーを扱えず、$primaryKey に渡せるのは 1 列だけなので、そのままでは fresh()・update()・
 * delete() が、その 1 列の値が同じ別の行まで対象にしてしまう。使うモデルは、$primaryKey に
 * compositeKeyColumns() のうちの 1 列を置き、主キーの列を compositeKeyColumns() で返す。
 * find() は $primaryKey の 1 列だけで引くので、ほかの列を区別しない。
 */
trait HasCompositePrimaryKey
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->whereCompositeKey($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->whereCompositeKey($query);
    }

    /**
     * 主キーを構成する列。
     *
     * @return list<string>
     */
    abstract protected function compositeKeyColumns(): array;

    protected function initializeHasCompositePrimaryKey(): void
    {
        $this->incrementing = false;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    private function whereCompositeKey(Builder $query): Builder
    {
        foreach ($this->compositeKeyColumns() as $column) {
            $query->where($column, $this->getOriginal($column, $this->getAttribute($column)));
        }

        return $query;
    }
}
