<?php

namespace App\Services\LegacyImport;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * 旧 DB の異常データを数える SQL の 1 つと、docs/migration-anomalies.md に書いた件数(#819)。
 *
 * 結果を、比べる列(columns)だけの行にして、文書の件数(expected)と比べる。行は列の値をそのまま文字にして比べる
 * (NULL は 'NULL')。文書で件数が決まっていない列(10 の cocktails など)は、columns に入れずに比べない。
 */
final readonly class LegacyAnomalyCheck
{
    /**
     * @param  string  $section  docs/migration-anomalies.md の節の番号(「5 定義 1」のように、節の中の SQL を分ける語を足してよい)
     * @param  string  $sql  文書の SQL と同じ文(末尾の ; は付けない。文書との一致は LegacyAnomalyChecksTest が確かめる)
     * @param  list<string>  $columns  文書と比べる列
     * @param  list<list<string>>  $expected  文書の件数。比べる列の値を、結果の行の順に並べたもの
     * @param  bool  $distinct  比べる列の値が同じ行を 1 行にまとめてから比べる(行の数は countRows で比べる)
     * @param  int|null  $countRows  まとめる前の行の数(文書に書いてあるとき)
     */
    public function __construct(
        public string $section,
        public string $label,
        public string $sql,
        public array $columns,
        public array $expected,
        public bool $distinct = false,
        public ?int $countRows = null,
    ) {}

    /**
     * SQL を流して、文書の件数と比べた結果を返す。
     *
     * @return array{actual: list<string>, expected: list<string>, matches: bool}
     */
    public function run(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->select($this->sql) as $row) {
            $values = (array) $row;
            $rows[] = array_map(
                fn (string $column): string => array_key_exists($column, $values)
                    ? self::text($values[$column])
                    : throw new RuntimeException("{$this->section} の SQL の結果に列 {$column} が無い"),
                $this->columns,
            );
        }
        $raw = count($rows);
        if ($this->distinct) {
            $rows = array_values(array_unique($rows, SORT_REGULAR));
        }

        $actual = $this->lines($rows, $this->countRows === null ? null : $raw);
        $expected = $this->lines($this->expected, $this->countRows);

        return ['actual' => $actual, 'expected' => $expected, 'matches' => $actual === $expected];
    }

    /**
     * 行を「列=値, 列=値」の文字にする。行の数も比べるときは、先頭に「行数=<数>」を置く。行が無ければ「(行なし)」。
     *
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function lines(array $rows, ?int $count): array
    {
        $lines = $count === null ? [] : ["行数={$count}"];
        foreach ($rows as $row) {
            $pairs = [];
            foreach ($this->columns as $i => $column) {
                $pairs[] = $column.'='.($row[$i] ?? '');
            }
            $lines[] = implode(', ', $pairs);
        }

        return $lines === [] ? ['(行なし)'] : $lines;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
