<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\BrandImporter;
use App\Services\LegacyImport\CategoryImporter;
use App\Services\LegacyImport\CocktailImporter;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\MiscImporter;
use Illuminate\Console\Command;

/**
 * 旧 DB(接続 legacy)のデータを新しい DB に移す。--only で部分を選ぶと、その部分だけを移す。
 *
 * 部分によっては、先に移した部分の行を外部キーで指す(カクテルは技法・氷・用語辞典を、銘柄はカテゴリ木のノードを指す)ため、
 * --only を付けないときは PARTS に書いた順に流す。
 */
final class ImportLegacy extends Command
{
    protected $signature = 'legacy:import {--only= : 移す部分の名前。付けなければすべての部分を順に移す}';

    protected $description = '旧 DB のデータを新しい DB に移す';

    /** @var array<string, class-string<LegacyImporter>> 部分の名前 => 移す処理(流す順) */
    private const PARTS = [
        'misc' => MiscImporter::class,
        'categories' => CategoryImporter::class,
        'brands' => BrandImporter::class,
        'cocktails' => CocktailImporter::class,
    ];

    public function handle(): int
    {
        $only = $this->option('only');
        if (is_string($only) && ! array_key_exists($only, self::PARTS)) {
            $this->error("--only に知らない部分が渡された: {$only}(選べるもの: ".implode(', ', array_keys(self::PARTS)).')');

            return self::FAILURE;
        }

        $parts = is_string($only) ? [$only => self::PARTS[$only]] : self::PARTS;
        foreach ($parts as $name => $importer) {
            $this->info("{$name}:");
            foreach ($this->laravel->make($importer)->import() as $table => $count) {
                $this->line("{$table}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
