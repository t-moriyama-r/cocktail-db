<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 言い換え辞書の 1 語。取り込んだ材料の名前(name)を、木のノードの名前(converted・middle)に読み替える。
 *
 * 読み替え先は、ノードの ID ではなく名前で持つ(理由は docs/schema.md の「paraphrases」)。
 */
#[Fillable(['name', 'converted', 'middle', 'note', 'command'])]
class Paraphrase extends Model {}
