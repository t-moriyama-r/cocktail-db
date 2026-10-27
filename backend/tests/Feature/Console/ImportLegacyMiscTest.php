<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\TestCase;

// legacy:import --only=misc(技法・氷・用語辞典・言い換え・スキップ語・ユーザー)。旧 DB を使うので db グループ

uses(RefreshDatabase::class)->group('db');

/**
 * legacy:import を、出力と終了コードを確かめられる形で呼ぶ。
 *
 * @param  array<string, string>  $options
 */
function importLegacyMisc(TestCase $test, array $options = ['--only' => 'misc']): PendingCommand
{
    $command = $test->artisan('legacy:import', $options);

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * 表の行を、比べたい列だけにして返す。
 *
 * @param  list<string>  $columns
 * @return list<array<mixed>>
 */
function miscTableRows(?string $connection, string $table, array $columns, string $orderBy = 'id'): array
{
    $rows = [];
    foreach (DB::connection($connection)->table($table)->orderBy($orderBy)->get($columns) as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

/**
 * misc で移す新しい表の件数。
 *
 * @return array<string, int>
 */
function miscCounts(): array
{
    $counts = [];
    foreach (['methods', 'ices', 'dictionary_categories', 'dictionaries', 'paraphrases', 'skip_words', 'users'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/**
 * 旧の用語辞典に、YouTube の開始位置だけを変えた行を足す。
 */
function addLegacyDictionary(int $id, ?int $hour, ?int $minute, ?int $second): void
{
    DB::connection('legacy')->table('t_dictionary')->insert([
        'id' => $id, 'name' => "用語{$id}", 'name_eng' => "Term {$id}", 'name_search' => "用語{$id}",
        'description' => '', 'category' => 1, 'youtube' => 'https://www.youtube.com/watch?v=example',
        'y_hour' => $hour, 'y_minute' => $minute, 'y_second' => $second,
    ]);
}

beforeEach(function () {
    LegacyFixture::load();
});

it('TC-33-01: 移した件数が、旧の表ごとの件数と一致する', function () {
    importLegacyMisc($this)->assertExitCode(0);

    $legacy = DB::connection('legacy');
    expect(miscCounts())->toBe([
        'methods' => $legacy->table('m_method')->count(),
        'ices' => $legacy->table('m_ice')->count(),
        'dictionary_categories' => $legacy->table('m_dictionary_category')->count(),
        'dictionaries' => $legacy->table('t_dictionary')->count(),
        'paraphrases' => $legacy->table('paraphrases')->count(),
        'skip_words' => $legacy->table('skip_db')->count(),
        'users' => $legacy->table('users')->count(),
    ])->and(miscCounts())->toBe([
        'methods' => 3, 'ices' => 1, 'dictionary_categories' => 1, 'dictionaries' => 1,
        'paraphrases' => 2, 'skip_words' => 1, 'users' => 1,
    ]);
});

it('TC-33-02: 移した表ごとの件数を出力する', function () {
    importLegacyMisc($this)
        ->expectsOutput('methods: 3')
        ->expectsOutput('ices: 1')
        ->expectsOutput('dictionary_categories: 1')
        ->expectsOutput('dictionaries: 1')
        ->expectsOutput('paraphrases: 2')
        ->expectsOutput('skip_words: 1')
        ->expectsOutput('users: 1')
        ->assertExitCode(0);
});

it('TC-33-03: 技法・氷・用語辞典の分類は、旧 ID と列の値をそのまま移す', function () {
    importLegacyMisc($this)->assertExitCode(0);

    expect(miscTableRows(null, 'methods', ['id', 'name', 'name_eng', 'description']))
        ->toBe(miscTableRows('legacy', 'm_method', ['id', 'name', 'name_eng', 'description']))
        ->and(miscTableRows(null, 'ices', ['id', 'name', 'name_eng']))
        ->toBe(miscTableRows('legacy', 'm_ice', ['id', 'name', 'name_eng']))
        ->and(miscTableRows(null, 'dictionary_categories', ['id', 'name', 'name_eng']))
        ->toBe(miscTableRows('legacy', 'm_dictionary_category', ['id', 'name', 'name_eng']));
});

it('TC-33-04: 用語辞典は旧 ID のまま移し、旧の category を dictionary_category_id に入れる', function () {
    importLegacyMisc($this)->assertExitCode(0);

    $columns = ['id', 'name', 'name_eng', 'name_search', 'description', 'youtube', 'image', 'image_txt', 'image_url'];
    expect(miscTableRows(null, 'dictionaries', [...$columns, 'dictionary_category_id']))
        ->toBe(array_map(
            fn (array $row) => [...$row, 'dictionary_category_id' => 1],
            miscTableRows('legacy', 't_dictionary', $columns),
        ));
});

it('TC-33-05: 用語辞典の YouTube の開始位置を秒にする(:dataset)', function (?int $hour, ?int $minute, ?int $second, ?int $expected) {
    addLegacyDictionary(10, $hour, $minute, $second);

    importLegacyMisc($this)->assertExitCode(0);

    expect(DB::table('dictionaries')->where('id', 10)->value('youtube_start_seconds'))->toBe($expected);
})->with([
    '3 つとも null' => [null, null, null, null],
    '分だけ' => [null, 11, null, 660],
    '秒だけ' => [null, null, 59, 59],
    '分と秒(0 分 0 秒)' => [null, 0, 0, 0],
    '時・分・秒' => [1, 2, 3, 3723],
]);

it('TC-33-06: 言い換えは converted・middle を名前のまま移す', function () {
    importLegacyMisc($this)->assertExitCode(0);

    $columns = ['name', 'converted', 'middle', 'note', 'command'];
    expect(miscTableRows(null, 'paraphrases', $columns, 'name'))
        ->toBe(miscTableRows('legacy', 'paraphrases', $columns, 'name'))
        ->and(DB::table('paraphrases')->where('name', '黒ラム')->value('middle'))->toBe('ダークラム')
        ->and(DB::table('paraphrases')->where('name', 'ドライジン')->value('middle'))->toBeNull();
});

it('TC-33-07: スキップ語を移す', function () {
    importLegacyMisc($this)->assertExitCode(0);

    expect(DB::table('skip_words')->orderBy('name')->pluck('name')->all())
        ->toBe(DB::connection('legacy')->table('skip_db')->orderBy('name')->pluck('name')->all());
});

it('TC-33-08: ユーザーは旧 ID のまま移し、パスワードのハッシュを変えない', function () {
    DB::connection('legacy')->table('users')->where('id', 1)->update([
        'remember_token' => 'legacy-remember-token',
        'created_at' => '2019-04-01 10:00:00',
        'updated_at' => '2023-04-01 10:00:00',
    ]);
    $legacy = DB::connection('legacy')->table('users')->where('id', 1)->sole();

    importLegacyMisc($this)->assertExitCode(0);

    $user = DB::table('users')->where('id', 1)->sole();
    $password = is_string($user->password) ? $user->password : throw new RuntimeException('password が文字列でない');
    expect($password)->toBe($legacy->password)
        ->and(Hash::check('password', $password))->toBeTrue()
        ->and([$user->name, $user->email, $user->remember_token, $user->created_at, $user->updated_at])
        ->toBe([$legacy->name, $legacy->email, 'legacy-remember-token', '2019-04-01 10:00:00', '2023-04-01 10:00:00'])
        ->and($user->email_verified_at)->toBeNull();
});

it('TC-33-09: 旧 ID のまま移した表は、legacy_id_maps に同じ値の対応を入れる', function () {
    importLegacyMisc($this)->assertExitCode(0);

    $maps = DB::table('legacy_id_maps')->orderBy('legacy_table')->orderBy('legacy_id')
        ->get(['legacy_table', 'legacy_id', 'new_table', 'new_id'])
        ->map(fn (object $row) => [$row->legacy_table, $row->legacy_id, $row->new_table, $row->new_id])
        ->all();
    expect($maps)->toBe([
        ['m_dictionary_category', 1, 'dictionary_categories', 1],
        ['m_ice', 1, 'ices', 1],
        ['m_method', 1, 'methods', 1],
        ['m_method', 2, 'methods', 2],
        ['m_method', 3, 'methods', 3],
        ['t_dictionary', 1, 'dictionaries', 1],
        ['users', 1, 'users', 1],
    ]);
});

it('TC-33-10: 2 回流しても、件数と中身が 1 回目と同じ', function () {
    importLegacyMisc($this)->assertExitCode(0);
    $first = [
        miscCounts(),
        miscTableRows(null, 'paraphrases', ['id', 'name', 'converted', 'middle'], 'name'),
        miscTableRows(null, 'skip_words', ['id', 'name'], 'name'),
        DB::table('legacy_id_maps')->count(),
    ];

    importLegacyMisc($this)->assertExitCode(0);

    expect([
        miscCounts(),
        miscTableRows(null, 'paraphrases', ['id', 'name', 'converted', 'middle'], 'name'),
        miscTableRows(null, 'skip_words', ['id', 'name'], 'name'),
        DB::table('legacy_id_maps')->count(),
    ])->toBe($first);
});

it('TC-33-11: 2 回目の前に旧の値が変わっていたら、新の値も変わる', function () {
    importLegacyMisc($this)->assertExitCode(0);
    $legacy = DB::connection('legacy');
    $legacy->table('m_method')->where('id', 1)->update(['name' => 'ビルド(変更)']);
    $legacy->table('paraphrases')->where('name', '黒ラム')->update(['converted' => 'ダークラム']);

    importLegacyMisc($this)->assertExitCode(0);

    expect(DB::table('methods')->where('id', 1)->value('name'))->toBe('ビルド(変更)')
        ->and(DB::table('paraphrases')->where('name', '黒ラム')->value('converted'))->toBe('ダークラム')
        ->and(DB::table('paraphrases')->count())->toBe(2);
});

it('TC-33-12: 旧の表が空でも、0 件として終わる', function () {
    DB::connection('legacy')->table('skip_db')->delete();
    DB::connection('legacy')->table('paraphrases')->delete();

    importLegacyMisc($this)
        ->expectsOutput('paraphrases: 0')
        ->expectsOutput('skip_words: 0')
        ->assertExitCode(0);
});

it('TC-33-13: 移している途中で失敗したら、例外で止まり、どの表にも入れない', function () {
    // 用語辞典の分類 9 は無いので、外部キーで用語辞典を入れられない
    DB::connection('legacy')->table('t_dictionary')->where('id', 1)->update(['category' => 9]);

    expect(fn () => importLegacyMisc($this)->run())->toThrow(QueryException::class);

    expect(array_sum(miscCounts()))->toBe(0)
        ->and(DB::table('legacy_id_maps')->count())->toBe(0);
});

it('TC-33-14: 移した後に足した行は、旧の最大の ID の次から番号が振られる', function () {
    DB::connection('legacy')->table('m_method')->insert([
        'id' => 20, 'name' => '番号の飛んだ技法', 'name_eng' => 'Gap', 'description' => '',
    ]);

    importLegacyMisc($this)->assertExitCode(0);

    expect(DB::table('methods')->insertGetId(['name' => '新しい技法', 'name_eng' => 'New', 'description' => '']))
        ->toBe(21);
});

it('TC-33-15: --only に知らない名前を渡すと、何も入れずに 1 で終わる', function () {
    importLegacyMisc($this, ['--only' => 'unknown'])
        ->expectsOutputToContain('unknown')
        ->assertExitCode(1);

    expect(array_sum(miscCounts()))->toBe(0);
});

it('TC-33-16: --only を付けないと、misc も流す', function () {
    importLegacyMisc($this, [])->assertExitCode(0);

    expect(miscCounts()['methods'])->toBe(3);
});
