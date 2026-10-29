<?php

use App\Models\LegacyIdMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyFixture;
use Tests\Support\LegacyImportCommand;

// legacy:import の descriptions(説明文の参照の記法を、新しいノードの ID に直す。#285)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/**
 * 旧の表と ID から、移した先のノードの ID を legacy_id_maps で引く。
 */
function descriptionNodeId(string $legacyTable, int $legacyId): int
{
    return LegacyIdMap::query()
        ->where('legacy_table', $legacyTable)
        ->where('legacy_id', $legacyId)
        ->where('new_table', 'categories')
        ->sole()
        ->new_id;
}

/**
 * 旧の行の説明文を書き換える。
 *
 * @param  array<string, string|null>  $values
 */
function setLegacyDescription(string $table, int $id, array $values): void
{
    DB::connection('legacy')->table($table)->where('id', $id)->update($values);
}

/**
 * 新しい表の行の列の値。
 */
function newDescription(string $table, int $id, string $column = 'description'): mixed
{
    return DB::table($table)->where('id', $id)->value($column);
}

/**
 * 説明文を持つ新しい表の、説明文の列すべて。
 *
 * @return array<string, list<array<mixed>>>
 */
function descriptionSnapshot(): array
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

it('TC-285-01: @p・@m を、移した先のノードの ID の @n にする(旧の ID がぶつかるカテゴリ 3 とミドル 3 は別のノード)', function () {
    setLegacyDescription('t_cocktails', 1, ['description' => 'ベースは@p1@。@p3@の@m3@も合う。']);

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    $category3 = descriptionNodeId('m_category', 3);
    $middle3 = descriptionNodeId('m_middle', 3);
    expect($category3)->not->toBe($middle3)
        ->and(newDescription('cocktails', 1))
        ->toBe('ベースは@n'.descriptionNodeId('m_category', 1).'@。@n'.$category3.'@の@n'.$middle3.'@も合う。');
});

it('TC-285-02: 大文字の @P・@M も @n にし、@C・@B・@D は ID を変えずに小文字にする', function () {
    setLegacyDescription('t_cocktails', 1, ['description' => '@P2@と@M1@、@C2@・@B1@・@D1@']);

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    expect(newDescription('cocktails', 1))->toBe(
        '@n'.descriptionNodeId('m_category', 2).'@と@n'.descriptionNodeId('m_middle', 1).'@、@c2@・@b1@・@d1@'
    );
});

it('TC-285-03: 小文字の @c・@b・@d は、そのまま残す', function () {
    setLegacyDescription('t_cocktails', 1, ['description' => '@c2@と@b1@と@d1@']);

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    expect(newDescription('cocktails', 1))->toBe('@c2@と@b1@と@d1@');
});

it('TC-285-04: 対応するノードが無い @p・@m は、記法を外して前後の文字だけを残し、件数に数える', function () {
    setLegacyDescription('t_cocktails', 1, ['description' => '前@p99@後、@M99@。@p1@']);

    LegacyImportCommand::run($this, [])
        ->expectsOutput('descriptions:')
        ->expectsOutput('@p・@m を @n に直した参照: 1')
        ->expectsOutput('対応するノードが無く記法を外した参照: 2')
        ->assertExitCode(0)
        ->run();

    expect(newDescription('cocktails', 1))->toBe('前後、。@n'.descriptionNodeId('m_category', 1).'@');
});

it('TC-285-05: 説明文を持つすべての表と列(種別・カテゴリ・ミドル・技法・用語・銘柄の description、カクテルの description・note)を直す', function () {
    setLegacyDescription('m_category_kinds', 1, ['description' => '種別@p1@']);
    // カテゴリの説明が、カテゴリの後に作るミドルを指す
    setLegacyDescription('m_category', 2, ['description' => 'カテゴリ@m3@']);
    setLegacyDescription('m_middle', 1, ['description' => 'ミドル@p3@']);
    setLegacyDescription('m_method', 1, ['description' => '技法@p4@']);
    setLegacyDescription('t_dictionary', 1, ['description' => '用語@p5@']);
    setLegacyDescription('m_brand', 1, ['description' => '銘柄@m2@']);
    setLegacyDescription('t_cocktails', 2, ['description' => 'カクテル@m1@', 'note' => '補足@P2@']);

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();

    $node = fn (string $table, int $id): string => '@n'.descriptionNodeId($table, $id).'@';
    expect(newDescription('categories', descriptionNodeId('m_category_kinds', 1)))->toBe('種別'.$node('m_category', 1))
        ->and(newDescription('categories', descriptionNodeId('m_category', 2)))->toBe('カテゴリ'.$node('m_middle', 3))
        ->and(newDescription('categories', descriptionNodeId('m_middle', 1)))->toBe('ミドル'.$node('m_category', 3))
        ->and(newDescription('methods', 1))->toBe('技法'.$node('m_category', 4))
        ->and(newDescription('dictionaries', 1))->toBe('用語'.$node('m_category', 5))
        ->and(newDescription('brands', 1))->toBe('銘柄'.$node('m_middle', 2))
        ->and(newDescription('cocktails', 2))->toBe('カクテル'.$node('m_middle', 1))
        ->and(newDescription('cocktails', 2, 'note'))->toBe('補足'.$node('m_category', 2));
});

it('TC-285-06: 直した数・直せなかった数・小文字にした数を、表をまたいで数えて出力する', function () {
    setLegacyDescription('m_category', 1, ['description' => '@p2@@m1@']);
    setLegacyDescription('m_brand', 2, ['description' => '@C1@@p98@']);
    setLegacyDescription('t_cocktails', 1, ['description' => '@M3@@B2@@d1@', 'note' => '@m97@']);

    LegacyImportCommand::run($this, [])
        ->expectsOutput('descriptions:')
        ->expectsOutput('@p・@m を @n に直した参照: 3')
        ->expectsOutput('対応するノードが無く記法を外した参照: 2')
        ->expectsOutput('@C・@B・@D を小文字にした参照: 2')
        ->assertExitCode(0)
        ->run();
});

it('TC-285-07: 2 回流しても、説明文と出力する件数は変わらない(descriptions だけを流し直しても同じ)', function () {
    setLegacyDescription('m_category', 1, ['description' => '@m3@と@p99@']);
    setLegacyDescription('t_cocktails', 1, ['description' => '@P2@', 'note' => '@C1@']);

    LegacyImportCommand::run($this, [])->assertExitCode(0)->run();
    $first = descriptionSnapshot();

    LegacyImportCommand::run($this, [])
        ->expectsOutput('@p・@m を @n に直した参照: 2')
        ->expectsOutput('対応するノードが無く記法を外した参照: 1')
        ->expectsOutput('@C・@B・@D を小文字にした参照: 1')
        ->assertExitCode(0)
        ->run();
    expect(descriptionSnapshot())->toBe($first);

    LegacyImportCommand::run($this, ['--only' => 'descriptions'])
        ->expectsOutput('@p・@m を @n に直した参照: 2')
        ->expectsOutput('対応するノードが無く記法を外した参照: 1')
        ->expectsOutput('@C・@B・@D を小文字にした参照: 1')
        ->assertExitCode(0)
        ->run();
    expect(descriptionSnapshot())->toBe($first);
});

it('TC-285-08: 参照の記法の形でないものは書き換えない', function (?string $text) {
    setLegacyDescription('t_cocktails', 1, ['description' => $text]);

    LegacyImportCommand::run($this, [])
        ->expectsOutput('@p・@m を @n に直した参照: 0')
        ->expectsOutput('対応するノードが無く記法を外した参照: 0')
        ->assertExitCode(0)
        ->run();

    expect(newDescription('cocktails', 1))->toBe($text);
})->with([
    'null' => [null],
    '空の文字列' => [''],
    '記法の無い文' => ['ジンをトニックで割る。'],
    'ID の無い記法(旧の決め打ちの名前のノードが無かったもの)' => ['@p@と@m@'],
    '0 で始まる ID' => ['@p0@・@m01@'],
    '閉じていない記法' => ['前に@p12'],
    '種類がほかの英字' => ['@x12@・@n3@'],
    'メールアドレス' => ['admin@example.com'],
]);

it('TC-285-09: 移した先の行が無い(ほかの部分をまだ移していない)ときは、どの行かを添えて止め、何も書かない', function () {
    setLegacyDescription('m_category', 1, ['description' => '@m3@']);
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();
    $before = descriptionSnapshot();

    expect(fn () => LegacyImportCommand::run($this, ['--only' => 'descriptions'])->run())
        ->toThrow(UnexpectedValueException::class, 'm_method の id 1 を移した先の methods の行が無い');

    expect(descriptionSnapshot())->toBe($before);
});

it('TC-285-10: --only を付けないと、ほかの部分を移した後に説明文を直す', function () {
    setLegacyDescription('t_dictionary', 1, ['description' => '@p1@']);

    LegacyImportCommand::run($this, [])
        ->expectsOutput('cocktails:')
        ->expectsOutput('descriptions:')
        ->assertExitCode(0)
        ->run();

    expect(newDescription('dictionaries', 1))->toBe('@n'.descriptionNodeId('m_category', 1).'@');
});

it('TC-285-11: 記法の無い行は書かない(ほかの部分が入れた説明文と更新日時をそのまま残す)', function () {
    setLegacyDescription('t_cocktails', 1, ['description' => '@p1@']);
    LegacyImportCommand::run($this, ['--only' => 'misc'])->assertExitCode(0)->run();
    LegacyImportCommand::run($this, ['--only' => 'categories'])->assertExitCode(0)->run();
    LegacyImportCommand::run($this, ['--only' => 'brands'])->assertExitCode(0)->run();
    LegacyImportCommand::run($this, ['--only' => 'cocktails'])->assertExitCode(0)->run();
    $before = DB::table('cocktails')->where('id', '<>', 1)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    $updatedAt = DB::table('cocktails')->where('id', 1)->value('updated_at');

    LegacyImportCommand::run($this, ['--only' => 'descriptions'])->assertExitCode(0)->run();

    expect(DB::table('cocktails')->where('id', '<>', 1)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before)
        ->and(DB::table('cocktails')->where('id', 1)->value('updated_at'))->toBe($updatedAt)
        ->and(newDescription('cocktails', 1))->toBe('@n'.descriptionNodeId('m_category', 1).'@');
});
