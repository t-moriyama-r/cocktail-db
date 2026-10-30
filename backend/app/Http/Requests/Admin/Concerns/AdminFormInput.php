<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\YoutubeInput;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * 管理画面の保存のリクエスト(銘柄 #314・カクテル #291)で共通の読み方。
 *
 * 画像つき(multipart)では真偽値が文字列で届くので直し、YouTube の URL が読めるかをほかの項目の誤りと一緒に返す。
 */
trait AdminFormInput
{
    /**
     * multipart(画像つき)では真偽値が文字列の "true"・"false" で届くので、boolean の規則が読める 1・0 に直す。
     *
     * @param  list<string>  $keys
     */
    protected function normalizeBooleanStrings(array $keys): void
    {
        $normalized = [];
        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $normalized[$key] = strtolower($value) === 'true' ? 1 : 0;
            }
        }
        $this->merge($normalized);
    }

    /**
     * YouTube の URL が読めるかを確かめる after の処理。
     *
     * @return callable(Validator): void
     */
    protected function youtubeValidation(): callable
    {
        return function (Validator $validator): void {
            if ($validator->errors()->has('youtube')) {
                return;
            }
            try {
                YoutubeInput::videoId($this->youtubeInput());
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('youtube', $e->getMessage());
            }
        };
    }

    /**
     * 入れた YouTube の URL か動画 ID。空なら null。
     */
    public function youtubeInput(): ?string
    {
        return $this->filled('youtube') ? $this->string('youtube')->toString() : null;
    }

    /**
     * 整数の項目の値。空なら null。
     */
    public function nullableInt(string $key): ?int
    {
        return $this->filled($key) ? $this->integer($key) : null;
    }

    /**
     * 文字列の項目の値。空なら null。
     */
    public function nullableString(string $key): ?string
    {
        return $this->filled($key) ? $this->string($key)->toString() : null;
    }
}
