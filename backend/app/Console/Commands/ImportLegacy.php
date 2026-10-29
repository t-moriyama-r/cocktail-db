<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\AffiliateImporter;
use App\Services\LegacyImport\BrandImporter;
use App\Services\LegacyImport\CategoryImporter;
use App\Services\LegacyImport\CocktailImporter;
use App\Services\LegacyImport\DescriptionImporter;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\ListsLegacyRows;
use App\Services\LegacyImport\MiscImporter;
use Illuminate\Console\Command;

/**
 * 旧 DB(接続 legacy)のデータを新しい DB に移す。--only で部分を選ぶと、その部分だけを移す。
 *
 * 部分によっては、先に移した部分の行を外部キーで指す(カクテルは技法・氷・用語辞典を、銘柄はカテゴリ木のノードを指す)ため、
 * --only を付けないときは PARTS に書いた順に流す。説明文の参照の記法(descriptions。#285)は、指す先をすべて移してから
 * 直すので最後に流す。
 *
 * 部分ごとに、移した後の件数を出す。人が見て扱いを決める旧の行を挙げる部分(ListsLegacyRows。説明文の壊れた記法。#393)は、
 * 件数の後にその行も出す。
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
        'affiliates' => AffiliateImporter::class,
        'cocktails' => CocktailImporter::class,
        'descriptions' => DescriptionImporter::class,
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
            $part = $this->laravel->make($importer);
            foreach ($part->import() as $table => $count) {
                $this->line("{$table}: {$count}");
            }
            if ($part instanceof ListsLegacyRows) {
                foreach ($part->listedRows() as $label => $rows) {
                    if ($rows !== []) {
                        $this->warn("{$label}の行: ".implode('、', $rows));
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
