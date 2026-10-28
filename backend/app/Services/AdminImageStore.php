<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 管理画面(ノード・銘柄・カクテル・用語の編集)で上げる画像を、public ディスク(storage/app/public)に置く。
 *
 * 旧アプリと同じく、画像の列には public ディスクの直下のファイル名だけを入れ、`/storage/<値>` で配信する
 * (移行した画像も同じ配置。legacy:verify-images)。ファイル名は、利用者の付けた名前を使わずサーバーで付ける。
 * 置いたファイルを指す行が無くなるとディスクに残り続けるので、差し替え・削除では前のファイルを消す。
 */
final class AdminImageStore
{
    /** 上限は 5MB(5,242,880 バイト)。大きすぎるファイルでディスクが埋まるのを防ぐ(旧は上限なし。#288) */
    private const MAX_KILOBYTES = 5 * 1024;

    /** 受け付ける画像の種類。中身から推定した拡張子で確かめる(名前の拡張子は見ない) */
    private const TYPES = ['jpeg', 'png', 'gif', 'webp'];

    /**
     * 画像の列に入れる値を返す。
     *
     * 新しいファイルを置けてから前のファイルを消す。置くのに失敗したとき、画像の無い行にしないため。
     */
    public function update(?UploadedFile $image, bool $removeImage, ?string $current): ?string
    {
        if ($image !== null) {
            $stored = $this->disk()->putFile('', $image);
            if ($stored === false) {
                throw new RuntimeException('画像を保存できなかった');
            }
            $this->deleteStored($current);

            return $stored;
        }

        if ($removeImage) {
            $this->deleteStored($current);

            return null;
        }

        return $current;
    }

    /**
     * 画像の列の値が public ディスクの直下のファイルを指すときだけ消す。
     *
     * 画像の列には外部の URL も入りうる(legacy:verify-images も数えている)。ディスクのファイルではないので消さない。
     * サーバーで付けるファイル名(英数字と拡張子)の形でない値も、このクラスが置いたファイルではないので消さない
     * (パスを含む値で、ディスクの別の場所を消さないため)。
     */
    private function deleteStored(?string $current): void
    {
        if ($current === null || preg_match('/\A[A-Za-z0-9_-]+(\.[A-Za-z0-9]+)?\z/', $current) !== 1) {
            return;
        }

        // トランザクションの中なら、コミットしてから消す。後の処理が落ちてロールバックされたときに、
        // 元の行が指すファイルを先に消してしまわないため(トランザクションの外なら、すぐ消える)
        DB::afterCommit(fn () => $this->disk()->delete($current));
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }

    /**
     * 管理 API の更新のリクエストで、画像(image)と画像だけを消す指定(remove_image)を確かめる規則。
     *
     * remove_image は boolean で受ける。画像を上げるリクエストは multipart なので true は文字列で届くが、
     * boolean の規則が通すのは true・false・1・0・"1"・"0" だけで、"true" などは 422 にする。通した値は
     * Request::boolean() と同じ真偽に読める。
     * 新しい画像と remove_image を同時に送られたときは、どちらをしたいのか分からないので受け付けない。
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'image' => ['nullable', 'file', 'mimes:'.implode(',', self::TYPES), 'max:'.self::MAX_KILOBYTES, 'prohibited_if_accepted:remove_image'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }
}
