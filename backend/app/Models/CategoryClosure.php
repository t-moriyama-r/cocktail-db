<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * カテゴリ木の閉包表(category_closures)の 1 行。祖先から子孫までの段数(depth)を持ち、自分自身は depth 0。
 *
 * Category の親・子・祖先・子孫のリレーションが中間の表として使う。
 * 行の書き換えは、木の整合(すべての祖先との行がそろっていること)を保つ CategoryTree(#24)が行う。
 */
class CategoryClosure extends Pivot
{
    protected $table = 'category_closures';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
        ];
    }
}
