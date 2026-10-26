<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * 表の定義(列・索引・外部キー)を、設計の文書と突き合わせやすい形に読み出す。
 * 行を入れずに表の形だけを確かめるので、行を書き換える場所が決まっている表(閉包表など)にも使える。
 */
final class SchemaShape
{
    /**
     * 列の名前ごとに [型, null 可, 既定値] を、表の列の順に返す。
     *
     * @return array<string, array{string, bool, mixed}>
     */
    public static function columns(string $table): array
    {
        return collect(Schema::getColumns($table))
            ->mapWithKeys(fn (array $column) => [$column['name'] => [$column['type'], $column['nullable'], $column['default']]])
            ->all();
    }

    /**
     * 索引の名前ごとに、索引の列を返す。
     *
     * @return array<string, list<string>>
     */
    public static function indexes(string $table): array
    {
        return collect(Schema::getIndexes($table))
            ->mapWithKeys(fn (array $index) => [$index['name'] => $index['columns']])
            ->all();
    }

    /**
     * 外部キーごとに [列, 指す表, 指す列, 削除のとき] を返す。
     *
     * @return array<int, array{string, string, string, string|null}>
     */
    public static function foreignKeys(string $table): array
    {
        return collect(Schema::getForeignKeys($table))
            ->map(fn (array $key) => [
                implode(',', $key['columns']),
                $key['foreign_table'],
                implode(',', $key['foreign_columns']),
                $key['on_delete'],
            ])
            ->all();
    }
}
