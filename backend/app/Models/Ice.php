<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * カクテルに入れる氷の種類。旧 m_ice と同じ列で、作成・更新の時刻を持たない。
 */
#[Fillable(['name', 'name_eng'])]
class Ice extends Model
{
    public $timestamps = false;
}
