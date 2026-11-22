<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\FilesystemException;

/**
 * 画像の列(image)の値が指すファイルが、public ディスクにあるかを見分ける。
 *
 * 画像の列には public ディスクからの相対パス(実際は直下のファイル名)が入り、`/storage/<値>` で配信する。
 * 外部の URL も入っているが、アップロードしたファイルではないので、ディスクでは確かめない。
 * 旧 DB の値を見る legacy:verify-images と、移した後の新しい DB の値を見る legacy:verify が使う。
 */
final class PublicDiskImage
{
    /**
     * 外部の URL(ディスクのファイルではない値)か。
     */
    public static function isExternal(string $image): bool
    {
        return preg_match('#^https?://#i', $image) === 1;
    }

    /**
     * `../` や制御文字を含む値は Flysystem が例外にするので、確かめずに無いものとして扱う。
     */
    public static function exists(Filesystem $disk, string $path): bool
    {
        try {
            return $disk->exists($path);
        } catch (FilesystemException) {
            return false;
        }
    }
}
