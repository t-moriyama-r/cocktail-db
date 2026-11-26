<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\LegacyAnomalyCheck;
use App\Services\LegacyImport\LegacyAnomalyChecks;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 旧 DB(接続 legacy)で、docs/migration-anomalies.md の SQL をすべて流して異常データを数え直し、文書の件数と比べる(#819)。
 *
 * 切り替えの日に、書き込みを止めた後・新 DB に移す前に流す(docs/cutover.md の 3 の 2)。旧 DB は読むだけ。
 * 文書と違うものがあれば、違ったものを並べて 2 で終わる。移すか止めるかは、docs/cutover.md の基準で人が決める。
 *
 * 終了コード: 0 すべて文書と合う / 1 SQL を流せなかった / 2 文書と違うものがある
 */
final class CountLegacyAnomalies extends Command
{
    protected $signature = 'legacy:count-anomalies';

    protected $description = '旧 DB で docs/migration-anomalies.md の SQL を流して異常データを数え直し、文書の件数と比べる';

    /** 文書と違うときの終了コード(人が見て決める) */
    private const DIFFERENT = 2;

    public function handle(): int
    {
        $connection = DB::connection('legacy');
        $rows = [];
        $different = [];
        try {
            $connection->statement(LegacyAnomalyChecks::PRELUDE);
            foreach (LegacyAnomalyChecks::all() as $check) {
                $result = $check->run($connection);
                $rows[] = [
                    $check->section,
                    $check->label,
                    implode("\n", $result['expected']),
                    implode("\n", $result['actual']),
                    $result['matches'] ? '合う' : '違う',
                ];
                if (! $result['matches']) {
                    $different[] = $check;
                }
            }
        } catch (QueryException $e) {
            $this->error('旧 DB で SQL を流せなかった: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['節', '内容', '文書の件数', '今の件数', '結果'], $rows);
        if ($different === []) {
            $this->info('すべて docs/migration-anomalies.md の件数と合う('.count($rows).' 件の SQL)');

            return self::SUCCESS;
        }

        $sections = implode('・', array_map(fn (LegacyAnomalyCheck $check): string => $check->section, $different));
        $this->error('docs/migration-anomalies.md の件数と違う: '.count($different)." 件({$sections})");
        $this->line('移すか止めるかは、docs/cutover.md の 3 の 2 の基準で決める');

        return self::DIFFERENT;
    }
}
