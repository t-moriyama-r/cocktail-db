<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\YoutubeInput;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * 管理画面の編集の入力(銘柄 #314・カクテル #291・用語 #315・ノード #121 など)に共通する読み方。
 *
 * YouTube の欄の規則(動画と開始位置の規則はカクテルと同じ。YoutubeInput)と、空の欄を null で読む方法、
 * 画像つき(multipart)で文字列で届く真偽値の直しを持つ。
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
            $normalized[$key] = self::booleanFromString($this->input($key));
        }
        $this->merge(array_filter($normalized, fn (mixed $value): bool => $value !== null));
    }

    /**
     * 文字列の "true"・"false"(大文字・小文字を問わない)を 1・0 にする。それ以外の値は null(直さない)。
     */
    protected static function booleanFromString(mixed $value): ?int
    {
        if (! is_string($value) || ! in_array(strtolower($value), ['true', 'false'], true)) {
            return null;
        }

        return strtolower($value) === 'true' ? 1 : 0;
    }

    /**
     * YouTube の URL か動画 ID と、開始位置の時・分・秒の規則。
     *
     * $prefix は項目の名前の前に付ける語。ノードの編集でその場で作る銘柄の欄(`brand_youtube` など。#121)のように、
     * 1 つのリクエストに YouTube の欄が 2 組あるときに使う。
     *
     * @return array<string, list<string>>
     */
    protected function youtubeRules(string $prefix = ''): array
    {
        return [
            // YouTube の URL か動画 ID
            $prefix.'youtube' => ['nullable', 'string', 'max:255'],
            $prefix.'youtube_start_hour' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_HOUR],
            $prefix.'youtube_start_minute' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
            $prefix.'youtube_start_second' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
        ];
    }

    /**
     * YouTube の URL が読めるかを、ほかの項目の誤りと一緒に返す検証(FormRequest::after に入れる)。
     *
     * @return callable(Validator): void
     */
    protected function youtubeValidation(string $prefix = ''): callable
    {
        return function (Validator $validator) use ($prefix): void {
            if ($validator->errors()->has($prefix.'youtube')) {
                return;
            }
            try {
                YoutubeInput::videoId($this->youtubeInput($prefix));
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add($prefix.'youtube', $e->getMessage());
            }
        };
    }

    /**
     * 入れた YouTube の URL か動画 ID。空なら null。
     */
    public function youtubeInput(string $prefix = ''): ?string
    {
        return $this->nullableString($prefix.'youtube');
    }

    /**
     * 入れた開始位置を秒にする。動画が無いか、時・分・秒が 3 つとも空なら null。
     */
    public function youtubeStartSeconds(?string $videoId, string $prefix = ''): ?int
    {
        return YoutubeInput::startSeconds(
            $videoId,
            $this->nullableInt($prefix.'youtube_start_hour'),
            $this->nullableInt($prefix.'youtube_start_minute'),
            $this->nullableInt($prefix.'youtube_start_second'),
        );
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
