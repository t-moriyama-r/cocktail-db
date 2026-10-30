<?php

namespace App\Console\Commands;

use App\Services\LegacySearchCompare\SearchCase;
use App\Services\LegacySearchCompare\SearchComparer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

/**
 * 旧の条件検索と新しい条件検索に同じ条件の組を流し、当たったカクテルの集合を突き合わせる(#169)。読むだけで、何も書き換えない。
 *
 * 旧の検索は旧 DB(接続 legacy)に旧と同じ SQL を流して再現し、新は旧の条件を新しい ID に直して流す(SearchComparer)。
 * 差分のうち、docs/search-expected-diffs.md の節(ExpectedDiff)で説明できないものだけを一覧にして失敗する。
 *
 * 条件の組は既定で tests/Fixtures/search-cases.json を読む。デプロイは tests/ を配らないので、サーバーで流すときは
 * --cases でファイルを渡す。
 */
final class CompareLegacySearch extends Command
{
    protected $signature = 'legacy:compare-search
        {--cases= : 条件の組の JSON のパス(既定は tests/Fixtures/search-cases.json)}';

    protected $description = '旧と新の条件検索に同じ条件を流して結果を突き合わせ、期待する差分で説明できない差分があれば一覧を出して失敗する';

    public function handle(SearchComparer $comparer): int
    {
        $option = $this->option('cases');
        $path = is_string($option) && $option !== '' ? $option : base_path('tests/Fixtures/search-cases.json');

        try {
            $cases = $this->readCases($path);
        } catch (InvalidArgumentException|JsonException $e) {
            $this->error("条件の組を読めない({$path}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $rows = [];
        $failures = [];
        $skipped = 0;
        foreach ($cases as $case) {
            $result = $comparer->compare($case);
            if ($result->skipped !== null) {
                $skipped++;
                $rows[] = [$case->id, $case->mode, '-', '-', '-', "流さない: {$result->skipped}"];

                continue;
            }

            $explained = [];
            foreach ($result->explained as $label => $count) {
                $explained[] = "{$label}: {$count} 件";
            }
            $rows[] = [
                $case->id,
                $case->mode,
                (string) $result->legacyCount,
                (string) $result->newCount,
                $explained === [] ? '-' : implode("\n", $explained),
                $result->failed() ? sprintf('説明できない差分 %d 件', count($result->unexplained)) : '合う',
            ];
            foreach ($result->unexplained as $line) {
                $failures[] = [$case->id, $line];
            }
        }

        $this->table(['組', '方式', '旧', '新', '説明できた差分', '結果'], $rows);

        if ($failures === []) {
            $this->info(sprintf('説明できない差分なし(%d 組。流さなかった組 %d)', count($cases), $skipped));

            return self::SUCCESS;
        }

        $this->error(sprintf('期待する差分(docs/search-expected-diffs.md)で説明できない差分がある(%d 件):', count($failures)));
        $this->table(['組', '内容'], $failures);

        return self::FAILURE;
    }

    /**
     * @return list<SearchCase>
     *
     * @throws InvalidArgumentException|JsonException
     */
    private function readCases(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidArgumentException('ファイルが無い');
        }

        $raw = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            throw new InvalidArgumentException('条件の組の並び(空でない配列)でない');
        }

        $cases = [];
        $ids = [];
        foreach ($raw as $index => $item) {
            $case = SearchCase::fromArray($item, $index);
            if (isset($ids[$case->id])) {
                throw new InvalidArgumentException("組の id が重なっている: {$case->id}");
            }
            $ids[$case->id] = true;
            $cases[] = $case;
        }

        return $cases;
    }
}
