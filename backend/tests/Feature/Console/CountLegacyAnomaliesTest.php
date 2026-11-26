<?php

use App\Services\LegacyImport\LegacyAnomalyCheck;
use App\Services\LegacyImport\LegacyAnomalyChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\TestCase;

// legacy:count-anomalies(#819。切り替えの日に、本番の旧 DB で docs/migration-anomalies.md の SQL を流して数え直す)。
// 文書の SQL・件数との突き合わせは DB を使わない。SQL を流すものは旧 DB(LegacyFixture)を使うので db グループ

/**
 * docs/migration-anomalies.md の ```sql のブロックの文を、書かれた順に出す。空白は 1 つにまとめ、末尾の ; は外す。
 * comments は、その文の前にある -- の行(前の文の後から。SET の文はまたぐ)。
 *
 * @return list<array{sql: string, comments: string}>
 */
function anomalyDocStatements(): array
{
    $doc = (string) file_get_contents(base_path('../docs/migration-anomalies.md'));
    preg_match_all('/^```sql\n(.*?)^```$/ms', $doc, $blocks);
    $statements = [];
    foreach ($blocks[1] as $block) {
        $current = '';
        $comments = '';
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                $comments .= $line."\n";

                continue;
            }
            $current .= $line."\n";
            if (! str_ends_with(rtrim($line), ';')) {
                continue;
            }
            $sql = normalizeAnomalySql(rtrim(trim($current), ';'));
            $statements[] = ['sql' => $sql, 'comments' => $comments];
            $current = '';
            if (! str_starts_with($sql, 'SET ')) {
                $comments = '';
            }
        }
    }

    return $statements;
}

function normalizeAnomalySql(string $sql): string
{
    return (string) preg_replace('/\s+/', ' ', trim($sql));
}

/**
 * 文書の旧 DB の SQL。10 の「新しい DB」の SQL(新の表 cocktails を読む)と、SET の文を除く。
 *
 * @return list<array{sql: string, comments: string}>
 */
function anomalyDocLegacyQueries(): array
{
    return array_values(array_filter(
        anomalyDocStatements(),
        fn (array $statement): bool => ! str_starts_with($statement['sql'], 'SET ')
            && ! preg_match('/\bFROM cocktails\b/', $statement['sql']),
    ));
}

/**
 * legacy:count-anomalies を、出力と終了コードを確かめられる形で呼ぶ。
 */
function countLegacyAnomalies(TestCase $test): PendingCommand
{
    $command = $test->artisan('legacy:count-anomalies');

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * LegacyAnomalyChecks の、節の番号の check。
 */
function anomalyCheck(string $section): LegacyAnomalyCheck
{
    foreach (LegacyAnomalyChecks::all() as $check) {
        if ($check->section === $section) {
            return $check;
        }
    }

    throw new RuntimeException("節 {$section} の check が無い");
}

it('TC-819-01: 流す SQL は、文書の旧 DB の SQL と同じものが同じ順に並ぶ', function () {
    $doc = array_map(fn (array $statement): string => $statement['sql'], anomalyDocLegacyQueries());
    $checks = array_map(fn (LegacyAnomalyCheck $check): string => normalizeAnomalySql($check->sql), LegacyAnomalyChecks::all());

    expect($doc)->not->toBeEmpty()
        ->and($checks)->toBe($doc);
});

it('TC-819-02: 先に流す SET の文は、文書の SET の文と同じ', function () {
    $sets = array_values(array_unique(array_map(
        fn (array $statement): string => $statement['sql'],
        array_filter(anomalyDocStatements(), fn (array $statement): bool => str_starts_with($statement['sql'], 'SET ')),
    )));

    expect($sets)->toBe([LegacyAnomalyChecks::PRELUDE]);
});

it('TC-819-03: 比べる件数は、文書でその SQL の前のコメントに書いた数', function () {
    $doc = anomalyDocLegacyQueries();
    $checks = LegacyAnomalyChecks::all();
    expect($checks)->toHaveCount(count($doc));

    foreach ($checks as $i => $check) {
        // 1 行の結果なら、比べる値のどれも。行の一覧なら、行の数(まとめる前の数があればそれ)
        $numbers = count($check->expected) === 1 && $check->countRows === null
            ? $check->expected[0]
            : [(string) ($check->countRows ?? count($check->expected))];
        foreach ($numbers as $number) {
            $pattern = '/(?<![\d,])'.preg_quote(number_format((int) $number), '/').'(?![\d,])/u';
            expect(preg_match($pattern, $doc[$i]['comments']))
                ->toBe(1, "節 {$check->section} の {$number} が、文書のコメントに無い: {$doc[$i]['comments']}");
        }
    }
});

it('TC-819-04: 旧 DB ですべての SQL が流れ、文書と違うものを並べて 2 で終わる', function () {
    LegacyFixture::load();

    // LegacyFixture は、消えたカクテル 99 の材料の行 2 行と、カクテル 2 の同じ材料の組 2 組(量も同じものが 1 組)を持つ。
    // 4(銘柄のミドルの親とカテゴリが合わない)は銘柄 4 の 1 件で、文書と同じ数になる
    $exit = Artisan::call('legacy:count-anomalies');
    $output = Artisan::output();

    expect($exit)->toBe(2, $output)
        ->and($output)->toContain('rows_=2, cocktails=1')
        ->and($output)->toContain('groups_=2, rows_=4, same_amount_groups=1')
        ->and($output)->toContain('合う')
        ->and($output)->toContain('docs/migration-anomalies.md の件数と違う: '.(count(LegacyAnomalyChecks::all()) - 1).' 件')
        ->and($output)->toContain('docs/cutover.md の 3 の 2 の基準で決める');
})->group('db');

it('TC-819-05: 旧 DB で SQL を流せなければ 1 で終わる', function () {
    LegacyFixture::load();
    DB::connection('legacy')->getSchemaBuilder()->drop('affiliates');

    countLegacyAnomalies($this)
        ->expectsOutputToContain('旧 DB で SQL を流せなかった')
        ->assertExitCode(1);
})->group('db');

it('TC-819-06: 結果が文書の件数と同じなら合う、違えば違うとし、比べた値を出す', function () {
    LegacyFixture::load();
    $legacy = DB::connection('legacy');
    $same = new LegacyAnomalyCheck('x', 'カクテルの数', 'SELECT COUNT(*) AS n FROM t_cocktails', ['n'], [['3']]);
    $other = new LegacyAnomalyCheck('x', 'カクテルの数', 'SELECT COUNT(*) AS n FROM t_cocktails', ['n'], [['4']]);

    expect($same->run($legacy))->toBe(['actual' => ['n=3'], 'expected' => ['n=3'], 'matches' => true])
        ->and($other->run($legacy))->toBe(['actual' => ['n=3'], 'expected' => ['n=4'], 'matches' => false]);
})->group('db');

it('TC-819-07: 14 A〜E は、記法の外に @・＠ が残る説明文の表・列・ID を出す', function () {
    LegacyFixture::load();
    $legacy = DB::connection('legacy');
    $legacy->table('t_cocktails')->where('id', 1)->update(['description' => '@p1@ は正しい記法']);
    $legacy->table('t_cocktails')->where('id', 3)->update(['note' => 'p29@']);
    $legacy->table('m_brand')->where('id', 2)->update(['description' => '＠p@p30@@']);

    expect(anomalyCheck('14 A〜E')->run($legacy)['actual'])->toBe([
        't=m_brand, c=description, id=2',
        't=t_cocktails, c=note, id=3',
    ]);
})->group('db');

it('TC-819-08: 14 F は、無いカクテルを指す記法の数と、その説明文の ID の組で比べる', function () {
    LegacyFixture::load();
    $legacy = DB::connection('legacy');
    // カクテル 1 は 2 個(999・998)、カクテル 2 は 1 個(大文字の @C)。@c3@ はあるカクテルなので数えない
    $legacy->table('t_cocktails')->where('id', 1)->update(['description' => '@c999@ と @c3@ と @c998@']);
    $legacy->table('t_cocktails')->where('id', 2)->update(['description' => '@C997@']);

    expect(anomalyCheck('14 F')->run($legacy)['actual'])->toBe([
        '行数=3',
        't=t_cocktails, c=description, id=1',
        't=t_cocktails, c=description, id=2',
    ]);
})->group('db');

it('TC-819-09: 15 は、ミドルの有名な銘柄が親のカテゴリの直下にあるものを出す', function () {
    LegacyFixture::load();
    $legacy = DB::connection('legacy');
    // 銘柄 2 をミドル 3(ダークラム。親はカテゴリ 2)から外してカテゴリ 2 の直下にし、ミドル 3 の有名な銘柄にする
    $legacy->table('m_brand')->where('id', 2)->update(['middle_category' => null]);
    $legacy->table('m_middle')->where('id', 3)->update(['famous_brand_id' => 2]);

    expect(anomalyCheck('15')->run($legacy)['actual'])->toBe(['middle_id=3, brand_id=2']);
})->group('db');
