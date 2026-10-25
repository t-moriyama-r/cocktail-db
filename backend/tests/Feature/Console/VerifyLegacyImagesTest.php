<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyFixture;
use Tests\TestCase;

// legacy:verify-images(旧 DB の画像の列が指すファイルが、新アプリの public ディスクにあるか)。旧 DB を使うので db グループ

/**
 * 旧データの画像の列をいったん空にしてから、渡した値を入れる。
 *
 * @param  array<string, array<int, string|null>>  $images  表 => [id => 画像の列の値]
 */
function setLegacyImages(array $images): void
{
    $connection = DB::connection('legacy');
    foreach (['t_cocktails', 'm_category', 'm_middle', 'm_brand', 't_dictionary'] as $table) {
        $connection->table($table)->update(['image' => null]);
    }
    foreach ($images as $table => $values) {
        foreach ($values as $id => $image) {
            $connection->table($table)->where('id', $id)->update(['image' => $image]);
        }
    }
}

/**
 * 旧データの表の、先頭の行の id。
 */
function firstLegacyId(string $table): int
{
    $id = DB::connection('legacy')->table($table)->orderBy('id')->value('id');

    return is_numeric($id) ? (int) $id : throw new RuntimeException("{$table} に行が無い");
}

/**
 * legacy:verify-images を、出力と終了コードを確かめられる形で呼ぶ。
 */
function verifyLegacyImages(TestCase $test): PendingCommand
{
    $command = $test->artisan('legacy:verify-images');

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

beforeEach(function () {
    LegacyFixture::load();
    Storage::fake('public');
});

it('TC-34-11: 画像の列が指すファイルがすべてあれば 0 で終わる', function () {
    $cocktail = firstLegacyId('t_cocktails');
    $brand = firstLegacyId('m_brand');
    Storage::disk('public')->put('cocktail.jpeg', 'x');
    Storage::disk('public')->put('空白 と 日本語.png', 'x');
    setLegacyImages([
        't_cocktails' => [$cocktail => 'cocktail.jpeg'],
        'm_brand' => [$brand => '空白 と 日本語.png'],
    ]);

    verifyLegacyImages($this)
        ->expectsOutput('found: 2')
        ->expectsOutput('missing: 0')
        ->assertExitCode(0);
})->group('db');

it('TC-34-12: 欠けているファイルがあれば、表・id・ファイル名を出して 1 で終わる', function () {
    $cocktail = firstLegacyId('t_cocktails');
    $category = firstLegacyId('m_category');
    $middle = firstLegacyId('m_middle');
    $dictionary = firstLegacyId('t_dictionary');
    Storage::disk('public')->put('here.jpeg', 'x');
    setLegacyImages([
        't_cocktails' => [$cocktail => 'here.jpeg'],
        'm_category' => [$category => 'gone-category.png'],
        'm_middle' => [$middle => 'gone-middle.png'],
        't_dictionary' => [$dictionary => 'gone-dictionary.png'],
    ]);

    verifyLegacyImages($this)
        ->expectsOutput('found: 1')
        ->expectsOutput('missing: 3')
        ->expectsTable(['表', 'id', 'ファイル名'], [
            ['m_category', $category, 'gone-category.png'],
            ['m_middle', $middle, 'gone-middle.png'],
            ['t_dictionary', $dictionary, 'gone-dictionary.png'],
        ])
        ->assertExitCode(1);
})->group('db');

it('TC-34-13: NULL・空文字は数えず、外部の URL は external として数えて欠けにしない', function () {
    $ids = DB::connection('legacy')->table('t_cocktails')->orderBy('id')->limit(3)->pluck('id')->all();
    expect($ids)->toHaveCount(3);
    setLegacyImages(['t_cocktails' => array_combine(
        array_map(fn (mixed $id) => is_numeric($id) ? (int) $id : 0, $ids),
        [null, '', 'https://example.com/a.jpg'],
    )]);

    verifyLegacyImages($this)
        ->expectsOutput('found: 0')
        ->expectsOutput('missing: 0')
        ->expectsOutput('external: 1')
        ->assertExitCode(0);
})->group('db');

it('TC-34-14: 2 つの表が同じファイルを指していても、両方あるものとして数える', function () {
    Storage::disk('public')->put('shared.jpeg', 'x');
    setLegacyImages([
        'm_middle' => [firstLegacyId('m_middle') => 'shared.jpeg'],
        't_dictionary' => [firstLegacyId('t_dictionary') => 'shared.jpeg'],
    ]);

    verifyLegacyImages($this)
        ->expectsOutput('found: 2')
        ->expectsOutput('missing: 0')
        ->assertExitCode(0);
})->group('db');

it('TC-34-15: ディスクの外を指す値(../)は、確かめずに欠けとして出す', function () {
    $cocktail = firstLegacyId('t_cocktails');
    setLegacyImages(['t_cocktails' => [$cocktail => '../../.env']]);

    verifyLegacyImages($this)
        ->expectsOutput('missing: 1')
        ->assertExitCode(1);
})->group('db');

it('TC-34-16: 旧データに画像が 1 件も無ければ 0 で終わる', function () {
    setLegacyImages([]);

    verifyLegacyImages($this)
        ->expectsOutput('found: 0')
        ->expectsOutput('missing: 0')
        ->assertExitCode(0);
})->group('db');
