<?php

namespace App\Http\Requests\Admin;

use App\Models\SkipWord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * スキップ語の 1 語の追加・更新(POST /api/admin/skip-words・PUT /api/admin/skip-words/{id}。#316)。
 */
class SaveSkipWordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $skipWord = $this->route('skip_word');

        return [
            // 列の長さ(docs/schema.md の「skip_words」。旧 skip_db と同じ 256)まで。ほかの語と重ならない
            'name' => [
                'required', 'string', 'max:256',
                Rule::unique('skip_words', 'name')->ignore($skipWord instanceof SkipWord ? $skipWord->id : null),
            ],
        ];
    }
}
