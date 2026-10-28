<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\YoutubeInput;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * 管理画面の編集の入力(銘柄 #314・用語 #315 など)に共通する、YouTube の欄の規則と、空の欄を null で読む方法。
 *
 * YouTube の動画と開始位置の規則はカクテル(#291)と同じ(YoutubeInput)。
 */
trait AdminFormInput
{
    /**
     * YouTube の URL か動画 ID と、開始位置の時・分・秒の規則。
     *
     * @return array<string, list<string>>
     */
    protected function youtubeRules(): array
    {
        return [
            // YouTube の URL か動画 ID
            'youtube' => ['nullable', 'string', 'max:255'],
            'youtube_start_hour' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_HOUR],
            'youtube_start_minute' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
            'youtube_start_second' => ['nullable', 'integer', 'min:0', 'max:'.YoutubeInput::MAX_MINUTE_OR_SECOND],
        ];
    }

    /**
     * YouTube の URL が読めるかを、ほかの項目の誤りと一緒に返す検証(FormRequest::after に入れる)。
     *
     * @return callable(Validator): void
     */
    protected function youtubeValidator(): callable
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
        return $this->nullableString('youtube');
    }

    /**
     * 入れた開始位置を秒にする。動画が無いか、時・分・秒が 3 つとも空なら null。
     */
    public function youtubeStartSeconds(?string $videoId): ?int
    {
        return YoutubeInput::startSeconds(
            $videoId,
            $this->nullableInt('youtube_start_hour'),
            $this->nullableInt('youtube_start_minute'),
            $this->nullableInt('youtube_start_second'),
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
