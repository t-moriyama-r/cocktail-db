<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * スキップ語(旧 skip_db)の 1 語。
 */
#[Fillable(['name'])]
class SkipWord extends Model {}
