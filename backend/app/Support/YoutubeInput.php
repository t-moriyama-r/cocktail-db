<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 管理画面で入れる YouTube の動画と開始位置を、保存する形にする(#314。カクテルの #291 と同じ規則)。
 *
 * 動画は、旧の Markup::youtube と同じく、URL でも動画 ID でも受けて動画 ID にする。開始位置は、画面で時・分・秒で
 * 入れたものを秒の 1 列(youtube_start_seconds。docs/schema.md の「YouTube の開始位置」)にする。
 */
final class YoutubeInput
{
    /** 分・秒の上限(旧と同じ) */
    public const MAX_MINUTE_OR_SECOND = 59;

    /** 時の上限。旧は上限が無かったが、秒にした値が列(unsignedInteger)に収まり、画面で入れうる範囲にする */
    public const MAX_HOUR = 99;

    /**
     * 入力(URL か動画 ID)を動画 ID にする。空なら null。
     *
     * `http` を含まなければ動画 ID とみなし、英数字・`_`・`-` だけならそのまま返す(旧は形を確かめず何でも保存した。新は止める)。含めば URL とみなし、`youtu` を含まなければ
     * YouTube の URL ではないとして止める。含めば `?v=` の後ろ(無ければ先頭)から、英数字・`_`・`-` が 10 文字以上
     * 続くところを動画 ID とする。
     *
     * @throws InvalidArgumentException YouTube の URL として読めないとき(旧は 500。新は 422 にする)
     */
    public static function videoId(?string $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (! str_contains($input, 'http')) {
            // 旧は形を確かめず何でも保存した。新は動画 ID の形(英数字・_・-)だけを受ける
            if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $input) !== 1) {
                throw new InvalidArgumentException('YouTube の動画 ID は英数字・_・- だけで入れてください');
            }

            return $input;
        }
        if (! str_contains($input, 'youtu')) {
            throw new InvalidArgumentException('YouTube の URL として正しくありません');
        }

        $offset = strpos($input, '?v=');
        if (preg_match('/[a-zA-Z0-9_-]{10,}/', $input, $m, 0, $offset === false ? 0 : $offset) !== 1) {
            throw new InvalidArgumentException('YouTube の URL から動画を読み取れません');
        }

        return $m[0];
    }

    /**
     * 時・分・秒を秒にする。動画が無いか、3 つとも空なら null(開始位置を決めていない)。空の欄は 0 として足す。
     */
    public static function startSeconds(?string $videoId, ?int $hour, ?int $minute, ?int $second): ?int
    {
        if ($videoId === null || ($hour === null && $minute === null && $second === null)) {
            return null;
        }

        return ($hour ?? 0) * 3600 + ($minute ?? 0) * 60 + ($second ?? 0);
    }

    /**
     * 秒を、画面の時・分・秒の欄に戻す。null なら 3 つとも null。
     *
     * @return array{hour: int|null, minute: int|null, second: int|null}
     */
    public static function splitSeconds(?int $seconds): array
    {
        if ($seconds === null) {
            return ['hour' => null, 'minute' => null, 'second' => null];
        }

        return [
            'hour' => intdiv($seconds, 3600),
            'minute' => intdiv($seconds % 3600, 60),
            'second' => $seconds % 60,
        ];
    }
}
