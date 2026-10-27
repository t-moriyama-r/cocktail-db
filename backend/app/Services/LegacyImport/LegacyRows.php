<?php

namespace App\Services\LegacyImport;

use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * 旧 DB(接続 legacy)の表の行を読む。
 *
 * 旧 DB は列の型を DB で守っていない行がありうるので、値を型として使う列は int・string で確かめ、
 * 合わなければ黙って 0 や空文字にせず、どの表のどの列かを添えて止める。
 */
final class LegacyRows
{
    /**
     * $table の行を、$orderBy の昇順で読む。
     *
     * @return list<array<mixed>>
     */
    public static function ordered(string $table, string $orderBy): array
    {
        $rows = [];
        foreach (DB::connection('legacy')->table($table)->orderBy($orderBy)->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $row
     *
     * @throws UnexpectedValueException 値が数でないとき
     */
    public static function int(array $row, string $column, string $table): int
    {
        return is_numeric($row[$column] ?? null)
            ? (int) $row[$column]
            : throw new UnexpectedValueException("{$table} の {$column} が数でない");
    }

    /**
     * @param  array<mixed>  $row
     *
     * @throws UnexpectedValueException 値が文字列でないとき
     */
    public static function string(array $row, string $column, string $table): string
    {
        return is_string($row[$column] ?? null)
            ? $row[$column]
            : throw new UnexpectedValueException("{$table} の {$column} が文字列でない");
    }
}
