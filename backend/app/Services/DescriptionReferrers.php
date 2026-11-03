<?php

namespace App\Services;

use App\Support\DescriptionParser;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 説明文(description・カクテルの note)から `@<種類><ID>@` で参照している行を探す。カクテルの削除(#579)と
 * ノードの削除(#629)が、消す前に参照が残らないかを調べるのに使う。
 *
 * 説明文の参照は外部キーではないので、消しても DB には拒まれず、参照が残って data:verify(#285 の 8)が失敗する。
 */
class DescriptionReferrers
{
    /**
     * 表 $table の説明文の列 $columns から、種類 $kind(`c`・`n` など。小文字)の $id を参照している行。ID の順。
     * $exceptId を渡すと、その ID の行は数えない(消す行自身の説明文からの参照は、一緒に消えるので数えない)。
     *
     * @param  list<string>  $columns
     * @return list<array{id: int, name: string}>
     */
    public function find(string $table, array $columns, string $kind, int $id, ?int $exceptId = null): array
    {
        // LIKE は候補を絞るだけ(照合順序で大文字の @C も拾う)。参照かどうかは、画面に出すとき(DescriptionParser)と同じ読み方で決める
        $rows = DB::table($table)
            ->where(function (QueryBuilder $query) use ($columns, $kind, $id): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%@{$kind}{$id}@%");
                }
            })
            ->when($exceptId !== null, fn (QueryBuilder $query) => $query->where('id', '<>', $exceptId))
            ->orderBy('id')
            ->get(['id', 'name', ...$columns]);

        $referrers = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (self::references($row->{$column}, $kind, $id)) {
                    $referrers[] = ['id' => is_numeric($row->id) ? (int) $row->id : 0, 'name' => is_string($row->name) ? $row->name : ''];
                    break;
                }
            }
        }

        return $referrers;
    }

    /**
     * 説明文 $text に、種類 $kind の $id を指す参照(`@c<ID>@`・`@C<ID>@` など)があるか。
     */
    private static function references(mixed $text, string $kind, int $id): bool
    {
        if (! is_string($text)) {
            return false;
        }
        preg_match_all(DescriptionParser::REFERENCE_PATTERN, $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (strtolower($m[1]) === $kind && (int) $m[2] === $id) {
                return true;
            }
        }

        return false;
    }
}
