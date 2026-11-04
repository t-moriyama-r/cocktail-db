<?php

namespace App\Http\Requests\Admin;

use App\Support\SettingDefinitions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 設定の 1 項目の書き換え(PUT /api/admin/settings。#132)。
 *
 * value を null(空の文字も、空の文字を null にするミドルウェアで null になる)にすると、保存した値を消して .env の値に戻す。
 */
class UpdateSettingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(SettingDefinitions $definitions): array
    {
        $key = $this->input('key');
        $definition = is_string($key) ? $definitions->find($key) : null;

        return [
            // 定義(SettingDefinitions)に無いキーは 422
            'key' => ['required', 'string', Rule::in($definitions->keys())],
            // 省いたときに黙って .env に戻さないよう、欄は必須(null は送れる)。形は項目ごとの規則で確かめる
            'value' => ['present', 'nullable', 'string', ...($definition->rules ?? ['max:255'])],
        ];
    }
}
