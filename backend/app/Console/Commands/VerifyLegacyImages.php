<?php

namespace App\Console\Commands;

use App\Support\PublicDiskImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 旧 DB(接続 legacy)の画像の列が指すファイルが、新アプリの public ディスク
 * (storage/app/public)にあるかを調べる。
 *
 * 旧アプリは画像を public ディスクに置き、画像の列にはそのディスクからの相対パス
 * (実際は直下のファイル名)を入れ、`/storage/<値>` で配信していた。新アプリも同じ配置で
 * 配信するので、ファイルが欠けていれば画像が出ない。
 * 画像の列には外部の URL も入っているが、アップロードしたファイルではないので数えない。
 */
final class VerifyLegacyImages extends Command
{
    protected $signature = 'legacy:verify-images';

    protected $description = '旧 DB の画像の列が指すファイルが、新アプリの storage/app/public にあるかを調べる';

    /** 画像の列(image)を持つ旧 DB の表 */
    private const TABLES = ['t_cocktails', 'm_category', 'm_middle', 'm_brand', 't_dictionary'];

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $found = 0;
        $external = 0;
        $missing = [];
        foreach ($this->imageReferences() as [$table, $id, $image]) {
            if (PublicDiskImage::isExternal($image)) {
                $external++;
            } elseif (PublicDiskImage::exists($disk, $image)) {
                $found++;
            } else {
                $missing[] = [$table, $id, $image];
            }
        }

        $this->line("found: {$found}");
        $this->line('missing: '.count($missing));
        $this->line("external: {$external}");
        if ($missing === []) {
            return self::SUCCESS;
        }

        $this->error('DB にあってファイルが無いもの:');
        $this->table(['表', 'id', 'ファイル名'], $missing);

        return self::FAILURE;
    }

    /**
     * NULL と空文字は画像の無い行なので除く。
     *
     * @return list<array{string, int, string}>
     */
    private function imageReferences(): array
    {
        $connection = DB::connection('legacy');
        $references = [];
        foreach (self::TABLES as $table) {
            $rows = $connection->table($table)
                ->whereNotNull('image')
                ->where('image', '<>', '')
                ->orderBy('id')
                ->get(['id', 'image']);
            foreach ($rows as $row) {
                /** @var object{id: int|string, image: string} $row */
                $references[] = [$table, (int) $row->id, $row->image];
            }
        }

        return $references;
    }
}
