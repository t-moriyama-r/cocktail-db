<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * カテゴリ木の閉包表(category_closures)の 1 行。祖先から子孫までの段数(depth)を持ち、自分自身は depth 0。
 *
 * 1 つのノードの追加・移動で、すべての祖先との行をそろえて書き換えないと木が食い違うため、
 * 行の書き換えは CategoryTree(#24)に限っている。
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
