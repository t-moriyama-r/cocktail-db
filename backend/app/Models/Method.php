<?php

namespace App\Models;

use Database\Factories\MethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * カクテルの技法(ビルド・シェイクなど)。旧 m_method と同じ列で、作成・更新の時刻を持たない。
 */
#[Fillable(['name', 'name_eng', 'description'])]
class Method extends Model
{
    /** @use HasFactory<MethodFactory> */
    use HasFactory;

    public $timestamps = false;
}
