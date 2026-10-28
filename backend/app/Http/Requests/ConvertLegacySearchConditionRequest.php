<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 旧サイトが保存した検索条件を直す(POST /api/legacy/search-condition。#290)。
 *
 * 旧の localStorage(キー searchInstant・searchUser)の categories・middles・methods をそのまま受ける。省いたキーは
 * 空の配列と同じに扱う。旧の画面は「全てチェック」で数百の ID を保存しうるので、本文で受ける(#163 と同じ理由)。
 */
class ConvertLegacySearchConditionRequest extends FormRequest
{
    /** 1 つの配列に入れられる ID の数。旧のカテゴリ・ミドルを全部選んでも届かない数にして、極端に大きい本文だけを断る */
    public const MAX_IDS = 5000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (['categories', 'middles', 'methods'] as $key) {
            $rules[$key] = ['sometimes', 'array', 'list', 'max:'.self::MAX_IDS];
            $rules["{$key}.*"] = ['required', 'integer'];
        }

        return $rules;
    }

    /**
     * @return list<int>
     */
    public function legacyIds(string $key): array
    {
        $validated = $this->validated($key, []);

        $ids = [];
        foreach (is_array($validated) ? $validated : [] as $id) {
            // rules() の integer を通った値(1・"1" など)なので、false にはならない
            $int = filter_var($id, FILTER_VALIDATE_INT);
            if ($int !== false) {
                $ids[] = $int;
            }
        }

        return $ids;
    }
}
