<?php

use App\Models\LegacyIdMap;
use App\Services\DescriptionMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;

// legacy:import の descriptions で、旧の説明文の壊れた記法を直す(#393。docs/migration-anomalies.md の 14)。旧 DB を使うので db グループ。
// 旧 DB の実物は無いので、LegacyFixture の旧の表に、14 の A〜F の形の説明文を持つ行を足して確かめる。値は形だけを合わせたもの。

uses(RefreshDatabase::class)->group('db');

/**
 * 旧の表と ID から、移した先のノードの ID の記法(`@n<ID>@`)を、legacy_id_maps で引く。
 */
function repairNode(string $legacyTable, int $legacyId): string
{
    $id = LegacyIdMap::query()
        ->where('legacy_table', $legacyTable)
        ->where('legacy_id', $legacyId)
        ->where('new_table', 'categories')
        ->sole()
        ->new_id;

    return "@n{$id}@";
}

/**
 * 旧のカクテルを足す。
 */
function addRepairLegacyCocktail(int $id, string $name, ?string $description = null, ?string $note = null): void
{
    DB::connection('legacy')->table('t_cocktails')->insert([
        'id' => $id, 'name' => $name, 'name_search' => $name, 'base' => 1, 'volume' => 10.5, 'method' => 1, 'ice' => 1,
        'recipe_count' => 0, 'description' => $description, 'note' => $note,
    ]);
}

/**
 * 旧のカテゴリを足す(種別 1 の下)。
 */
function addRepairLegacyCategory(int $id, string $name): void
{
    DB::connection('legacy')->table('m_category')->insert([
        'id' => $id, 'kind' => 1, 'name' => $name, 'name_search' => $name, 'name_eng' => $name,
    ]);
}

/**
 * 14 の A〜E の行を、すべて直せる形で足す。グラニュー糖(C)と、A・D・E の記法が指すカテゴリも足す。
 */
function addRepairTypoRows(): void
{
    addRepairLegacyCategory(58, 'サンプル58');
    addRepairLegacyCategory(30, 'サンプル30');
    addRepairLegacyCategory(29, 'サンプル29');
    addRepairLegacyCategory(6, 'グラニュー糖');

    // A: 2 つ目の記法が p@@p58@@
    addRepairLegacyCocktail(153, 'サンプルA', description: '材料は@p1@p@@p58@@を使う');
    // B: 種類の英字の無い記法が、同じ名前の別のカクテルを指す
    addRepairLegacyCocktail(3620, 'サンプルB1', description: '同じ名前の@3621@もある');
    addRepairLegacyCocktail(3621, 'サンプルB1', description: '同じ名前の@3620@もある');
    addRepairLegacyCocktail(5189, 'サンプルB2', description: '別の作り方は@5629@');
    addRepairLegacyCocktail(5629, 'サンプルB2', description: '別の作り方は@5189@と@c1@');
    // C: ID の無い @p@(グラニュー糖)
    addRepairLegacyCocktail(11, 'サンプルC', note: '@p@を少し足す');
    // D: 先頭の @ が全角で、記法が二重
    addRepairLegacyCocktail(238, 'サンプルD', note: '＠p@p30@@で割る');
    // E: 先頭の @ が無い
    addRepairLegacyCocktail(1334, 'サンプルE', note: 'あとはp29@を添える');
}

/**
 * 新しい表の行の列の値。
 */
function repairedText(string $table, int $id, string $column = 'description'): mixed
{
    return DB::table($table)->where('id', $id)->value($column);
}

/**
 * 説明文を持つ新しい表の、説明文の列すべて。
 *
 * @return array<string, list<array<mixed>>>
 */
function repairSnapshot(): array
{
    $snapshot = [];
    foreach (['categories' => ['description'], 'methods' => ['description'], 'dictionaries' => ['description'],
        'brands' => ['description'], 'cocktails' => ['description', 'note']] as $table => $columns) {
        $rows = [];
        foreach (DB::table($table)->orderBy('id')->get(['id', ...$columns]) as $row) {
            $rows[] = (array) $row;
        }
        $snapshot[$table] = $rows;
    }

    return $snapshot;
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-393-01: A〜E の壊れた記法を、表のとおりに直してから新しい記法にする', function () {
    addRepairTypoRows();

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    expect(repairedText('cocktails', 153))->toBe('材料は'.repairNode('m_category', 1).repairNode('m_category', 58).'を使う')
        ->and(repairedText('cocktails', 3620))->toBe('同じ名前の@c3621@もある')
        ->and(repairedText('cocktails', 3621))->toBe('同じ名前の@c3620@もある')
        ->and(repairedText('cocktails', 5189))->toBe('別の作り方は@c5629@')
        ->and(repairedText('cocktails', 5629))->toBe('別の作り方は@c5189@と@c1@')
        ->and(repairedText('cocktails', 11, 'note'))->toBe(repairNode('m_category', 6).'を少し足す')
        ->and(repairedText('cocktails', 238, 'note'))->toBe(repairNode('m_category', 30).'で割る')
        ->and(repairedText('cocktails', 1334, 'note'))->toBe('あとは'.repairNode('m_category', 29).'を添える');
});

it('TC-393-02: A〜E を直した数を出し、直さなかった行も記法の外の @ を取り除いた行も無ければ 0 を出す', function () {
    addRepairTypoRows();

    LegacyImportCommand::run($this, [])
        ->expectsOutput('壊れた記法(A〜E)を直した説明文: 8')
        ->expectsOutput('直す前の文が違い、壊れた記法(A〜E)を直さなかった説明文: 0')
        ->expectsOutput('記法の外の @・＠ を取り除いた説明文: 0')
        ->doesntExpectOutputToContain('の行: ')
        ->assertExitCode(0)
        ->run();
});

it('TC-393-03: C のグラニュー糖が旧のミドルにあれば、そのミドルのノードにする', function () {
    addRepairTypoRows();
    DB::connection('legacy')->table('m_category')->where('id', 6)->delete();
    DB::connection('legacy')->table('m_middle')->insert([
        'id' => 9, 'parent' => 1, 'name' => 'グラニュー糖', 'name_search' => 'グラニュー糖', 'name_eng' => 'Sugar',
        'description' => '', 'is_like' => 0,
    ]);

    LegacyImportCommand::run($this, [])->expectsOutput('壊れた記法(A〜E)を直した説明文: 8')->assertExitCode(0)->run();

    expect(repairedText('cocktails', 11, 'note'))->toBe(repairNode('m_middle', 9).'を少し足す');
});

it('TC-393-04: 直す前の文が違う A〜E の行は直さず、件数と行を出す(記法の外に残った @ は取り除く)', function (Closure $change, string $row, string $column, int $id, ?string $expected) {
    addRepairTypoRows();
    $change();

    LegacyImportCommand::run($this, [])
        ->expectsOutput('壊れた記法(A〜E)を直した説明文: 7')
        ->expectsOutput('直す前の文が違い、壊れた記法(A〜E)を直さなかった説明文: 1')
        ->expectsOutput("直す前の文が違い、壊れた記法(A〜E)を直さなかった説明文の行: {$row}")
        ->assertExitCode(0)
        ->run();

    // 期待値の {p<ID>} は、旧のカテゴリ <ID> を移した先のノードの記法
    $expected = $expected === null ? null : preg_replace_callback('/\{p(\d+)\}/', fn (array $m): string => repairNode('m_category', (int) $m[1]), $expected);
    expect(repairedText('cocktails', $id, $column))->toBe($expected);
})->with([
    'A が旧で直された' => [
        fn () => DB::connection('legacy')->table('t_cocktails')->where('id', 153)->update(['description' => '材料は@p1@@p58@']),
        't_cocktails.description 153', 'description', 153,
        '材料は{p1}{p58}',
    ],
    'A の壊れたところが 2 つある' => [
        fn () => DB::connection('legacy')->table('t_cocktails')->where('id', 153)->update(['description' => 'p@@p58@@とp@@p58@@']),
        't_cocktails.description 153', 'description', 153,
        // 記法の外の @ だけを取り除くので、記法の @p58@ は残る
        'p{p58}とp{p58}',
    ],
    'B が旧に無いカクテルを指す' => [
        fn () => DB::connection('legacy')->table('t_cocktails')->where('id', 3620)->update(['description' => '同じ名前の@9999@もある']),
        't_cocktails.description 3620', 'description', 3620, '同じ名前の9999もある',
    ],
    'B に種類の英字の無い記法が無い' => [
        fn () => DB::connection('legacy')->table('t_cocktails')->where('id', 5189)->update(['description' => '別の作り方は@c5629@']),
        't_cocktails.description 5189', 'description', 5189, '別の作り方は@c5629@',
    ],
    'C のグラニュー糖のノードが旧に無い' => [
        fn () => DB::connection('legacy')->table('m_category')->where('id', 6)->delete(),
        't_cocktails.note 11', 'note', 11, 'pを少し足す',
    ],
    'D が旧で書き換えられた' => [
        fn () => DB::connection('legacy')->table('t_cocktails')->where('id', 238)->update(['note' => 'ソーダで割る']),
        't_cocktails.note 238', 'note', 238, 'ソーダで割る',
    ],
    'E の行が旧で消えた' => [
        fn () => DB::connection('legacy')->table('t_cocktails')->where('id', 1334)->delete(),
        't_cocktails.note 1334', 'note', 1334, null,
    ],
]);

it('TC-393-05: E の p29@ が、記法の一部(@p29@)なら直さない', function () {
    addRepairTypoRows();
    DB::connection('legacy')->table('t_cocktails')->where('id', 1334)->update(['note' => 'あとは@p29@を添える']);

    LegacyImportCommand::run($this, [])
        ->expectsOutput('直す前の文が違い、壊れた記法(A〜E)を直さなかった説明文の行: t_cocktails.note 1334')
        ->assertExitCode(0)
        ->run();

    expect(repairedText('cocktails', 1334, 'note'))->toBe('あとは'.repairNode('m_category', 29).'を添える');
});

it('TC-393-06: E の p29@ が、前の記法と @ を分け合っていても直す', function () {
    addRepairTypoRows();
    DB::connection('legacy')->table('t_cocktails')->where('id', 1334)->update(['note' => '@p1@p29@を添える']);

    LegacyImportCommand::run($this, [])->expectsOutput('壊れた記法(A〜E)を直した説明文: 8')->assertExitCode(0)->run();

    expect(repairedText('cocktails', 1334, 'note'))->toBe(repairNode('m_category', 1).repairNode('m_category', 29).'を添える');
});

it('TC-393-07: 消えたカクテルを指す @c(F。大文字も)は、記法を取り除いて数える。あるカクテルを指す記法は残す', function () {
    addRepairLegacyCocktail(5202, 'サンプルF', description: '似たものに@c1@・@c9999@・@C9998@がある');
    setLegacyRepairDescription('m_brand', 1, '@c9997@を使う');

    LegacyImportCommand::run($this, [])
        ->expectsOutput('無いカクテルを指し記法を外した参照: 3')
        ->expectsOutput('記法の外の @・＠ を取り除いた説明文: 0')
        ->assertExitCode(0)
        ->run();

    expect(repairedText('cocktails', 5202))->toBe('似たものに@c1@・・がある')
        ->and(repairedText('brands', 1))->toBe('を使う');
});

it('TC-393-08: A〜F 以外の壊れた説明文は、記法の外の @・＠ を取り除いた文字で移し(止めない)、件数と表・列・ID を出す', function () {
    setLegacyRepairDescription('m_brand', 1, '問い合わせは admin@example.com へ');
    setLegacyRepairDescription('t_dictionary', 1, '前に@p12と＠と@x1@');
    DB::connection('legacy')->table('t_cocktails')->where('id', 2)->update(['description' => '@p@と@m@', 'note' => '@p0@・@n3@・@p1@']);

    LegacyImportCommand::run($this, [])
        ->expectsOutput('descriptions:')
        ->expectsOutput('記法の外の @・＠ を取り除いた説明文: 4')
        ->expectsOutput('記法の外の @・＠ を取り除いた説明文の行: t_dictionary.description 1、m_brand.description 1、t_cocktails.description 2、t_cocktails.note 2')
        ->assertExitCode(0)
        ->run();

    expect(repairedText('brands', 1))->toBe('問い合わせは adminexample.com へ')
        ->and(repairedText('dictionaries', 1))->toBe('前にp12ととx1')
        ->and(repairedText('cocktails', 2))->toBe('pとm')
        ->and(repairedText('cocktails', 2, 'note'))->toBe('p0・n3・'.repairNode('m_category', 1));
});

it('TC-393-09: 移した説明文には、記法の外の @ が残らない(管理画面で保存するときの検査を通る)', function () {
    addRepairTypoRows();
    addRepairLegacyCocktail(5294, 'サンプルF2', description: '@c9999@p@x');
    setLegacyRepairDescription('m_category', 2, '＠＠@@');
    setLegacyRepairDescription('m_method', 1, 'a@b@c1@');

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    $stray = [];
    foreach (repairSnapshot() as $table => $rows) {
        foreach ($rows as $row) {
            foreach ($row as $column => $text) {
                if ($column !== 'id' && is_string($text) && DescriptionMarkup::strayAtContext($text) !== null) {
                    $stray[] = "{$table}.{$column}: {$text}";
                }
            }
        }
    }
    expect($stray)->toBe([]);
});

it('TC-393-10: 2 回流しても、説明文と出力する件数・行は変わらない', function () {
    addRepairTypoRows();
    addRepairLegacyCocktail(5362, 'サンプルF3', description: '@c9999@と@');
    DB::connection('legacy')->table('t_cocktails')->where('id', 238)->update(['note' => 'ソーダで割る']);

    $expectOutput = fn () => LegacyImportCommand::run($this, [])
        ->expectsOutput('壊れた記法(A〜E)を直した説明文: 7')
        ->expectsOutput('無いカクテルを指し記法を外した参照: 1')
        ->expectsOutput('直す前の文が違い、壊れた記法(A〜E)を直さなかった説明文の行: t_cocktails.note 238')
        ->expectsOutput('記法の外の @・＠ を取り除いた説明文の行: t_cocktails.description 5362')
        ->assertExitCode(0)
        ->run();

    $expectOutput();
    $first = repairSnapshot();
    $expectOutput();

    expect(repairSnapshot())->toBe($first)
        ->and(repairedText('cocktails', 5362))->toBe('と');
});

/**
 * 旧の行の description を書き換える。
 */
function setLegacyRepairDescription(string $table, int $id, ?string $description): void
{
    DB::connection('legacy')->table($table)->where('id', $id)->update(['description' => $description]);
}
