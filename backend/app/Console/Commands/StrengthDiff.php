<?php

namespace App\Console\Commands;

use App\Models\Cocktail;
use App\Services\Recipe\StrengthCalculator;
use App\Services\Recipe\StrengthNotCalculableException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * カクテルが持っている度数(cocktails.volume)と、材料から計算し直した度数が違うカクテルを一覧にする。読むだけで、
 * 何も書き換えない(#160)。
 *
 * 度数は保存のときに計算して持つ値なので、後から銘柄の度数・ノードの有名な銘柄を変えると、保存し直すまで古いまま残る。
 * 移行した度数も旧の値をそのまま移していて(docs/decisions/strength.md の決まったこと 4)、旧で同じように古くなった
 * 値が混ざる。どれが古いかを人が見て直せるよう、違うものだけを出す。
 */
final class StrengthDiff extends Command
{
    protected $signature = 'cocktails:strength-diff';

    protected $description = '持っている度数と、材料から計算し直した度数が違うカクテルを一覧にする(書き換えない)';

    private const CHUNK = 500;

    public function handle(StrengthCalculator $calculator): int
    {
        $rows = [];
        $checked = 0;
        Cocktail::query()
            ->with(StrengthCalculator::RELATIONS)
            ->chunkById(self::CHUNK, function (Collection $cocktails) use ($calculator, &$rows, &$checked) {
                foreach ($cocktails as $cocktail) {
                    /** @var Cocktail $cocktail */
                    $checked++;
                    $row = self::diffRow($calculator, $cocktail);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
            });

        if ($rows === []) {
            $this->info(sprintf('違いなし(カクテル %d 件)', $checked));

            return self::SUCCESS;
        }

        $this->warn(sprintf('持っている度数と計算した度数が違うカクテル: %d 件(カクテル %d 件中)', count($rows), $checked));
        $this->table(['ID', '名前', '持っている度数', '計算した度数'], $rows);

        return self::SUCCESS;
    }

    /**
     * 持っている度数と計算した度数が違えば、一覧の 1 行を返す。同じなら null。
     *
     * 列は小数 1 桁で持つので、計算した値も小数 1 桁の文字列にして比べる(float のまま比べると、丸めの誤差で
     * 同じ値を違うとみなしうる)。計算できないカクテルも、度数を直すには材料を見直す必要があるので一覧に出す。
     *
     * @return array{int, string, string, string}|null
     */
    private static function diffRow(StrengthCalculator $calculator, Cocktail $cocktail): ?array
    {
        $stored = sprintf('%.1f', $cocktail->strength());
        try {
            $calculated = sprintf('%.1f', $calculator->forCocktail($cocktail));
        } catch (StrengthNotCalculableException|InvalidArgumentException $e) {
            return [$cocktail->id, $cocktail->name, $stored, '計算できない: '.$e->getMessage()];
        }

        return $calculated === $stored ? null : [$cocktail->id, $cocktail->name, $stored, $calculated];
    }
}
