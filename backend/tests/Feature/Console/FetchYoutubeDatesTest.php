<?php

use App\Models\Brand;
use App\Models\Cocktail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// youtube:fetch-dates(#74。旧 sys/gen の SystemController::insertYoutubeDatetime)を、MariaDB で確かめる。
// 旧は YouTube に問い合わせず、動画があって公開日時の無いカクテルに、そのカクテルの更新日時を公開日時として入れ、
// Eloquent の save で書いていた(更新日時は実行した時刻になる)。

uses(RefreshDatabase::class)->group('db');

/**
 * 列を直接書いたカクテルを作る(updated_at を過去の時刻にするため、作った後に書き換える)。
 */
function youtubeDatesCocktail(?string $youtube, ?string $youtubeCreatedAt, ?string $updatedAt): Cocktail
{
    $cocktail = Cocktail::factory()->create();
    DB::table('cocktails')->where('id', $cocktail->id)->update([
        'youtube' => $youtube,
        'youtube_created_at' => $youtubeCreatedAt,
        'updated_at' => $updatedAt,
    ]);

    return $cocktail;
}

/**
 * いまの列の値(DB に入っている文字列のまま)。
 *
 * @return array{youtube_created_at: mixed, updated_at: mixed}
 */
function youtubeDatesStored(Cocktail $cocktail): array
{
    $row = DB::table('cocktails')->where('id', $cocktail->id);

    return [
        'youtube_created_at' => (clone $row)->value('youtube_created_at'),
        'updated_at' => (clone $row)->value('updated_at'),
    ];
}

it('TC-74-05: 動画があって公開日時の無いカクテルに、更新日時を公開日時として入れ、更新日時を実行した時刻にする', function () {
    $cocktail = youtubeDatesCocktail('abcdefghijk', null, '2020-05-06 07:08:09');
    $this->travelTo('2026-10-28 12:34:56');

    $code = Artisan::call('youtube:fetch-dates');

    expect($code)->toBe(0)
        ->and(youtubeDatesStored($cocktail))->toBe([
            'youtube_created_at' => '2020-05-06 07:08:09',
            'updated_at' => '2026-10-28 12:34:56',
        ]);
});

it('TC-74-06: 公開日時が入っているカクテルは書き換えない(2 回流しても変わらない)', function () {
    $cocktail = youtubeDatesCocktail('abcdefghijk', '2019-01-01 00:00:00', '2020-05-06 07:08:09');
    $this->travelTo('2026-10-28 12:34:56');

    Artisan::call('youtube:fetch-dates');
    Artisan::call('youtube:fetch-dates');

    expect(youtubeDatesStored($cocktail))->toBe([
        'youtube_created_at' => '2019-01-01 00:00:00',
        'updated_at' => '2020-05-06 07:08:09',
    ]);
});

it('TC-74-07: 動画が null のカクテルは書き換えない', function () {
    $cocktail = youtubeDatesCocktail(null, null, '2020-05-06 07:08:09');
    $this->travelTo('2026-10-28 12:34:56');

    Artisan::call('youtube:fetch-dates');

    expect(youtubeDatesStored($cocktail))->toBe(['youtube_created_at' => null, 'updated_at' => '2020-05-06 07:08:09']);
});

it('TC-74-08: 動画が空文字のカクテルも、null でないので公開日時を入れる(旧の whereNotNull と同じ)', function () {
    $cocktail = youtubeDatesCocktail('', null, '2020-05-06 07:08:09');
    $this->travelTo('2026-10-28 12:34:56');

    Artisan::call('youtube:fetch-dates');

    expect(youtubeDatesStored($cocktail))->toBe([
        'youtube_created_at' => '2020-05-06 07:08:09',
        'updated_at' => '2026-10-28 12:34:56',
    ]);
});

it('TC-74-09: 更新日時が null のカクテルは、入れる値が無いので何も変えない', function () {
    $cocktail = youtubeDatesCocktail('abcdefghijk', null, null);
    $this->travelTo('2026-10-28 12:34:56');

    Artisan::call('youtube:fetch-dates');

    expect(youtubeDatesStored($cocktail))->toBe(['youtube_created_at' => null, 'updated_at' => null]);
});

it('TC-74-10: 銘柄の公開日時は書き換えない(旧もカクテルだけ)', function () {
    $brand = Brand::factory()->create(['youtube' => 'abcdefghijk', 'youtube_created_at' => null]);

    Artisan::call('youtube:fetch-dates');

    expect(Brand::query()->findOrFail($brand->id)->youtube_created_at)->toBeNull();
});

it('TC-74-11: 公開日時を入れた件数を出す', function () {
    youtubeDatesCocktail('abcdefghijk', null, '2020-05-06 07:08:09');
    youtubeDatesCocktail('lmnopqrstuv', null, '2020-05-07 07:08:09');
    youtubeDatesCocktail('wxyzabcdefg', '2019-01-01 00:00:00', '2020-05-06 07:08:09');
    youtubeDatesCocktail(null, null, '2020-05-06 07:08:09');

    $code = Artisan::call('youtube:fetch-dates');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('動画の公開日時を入れた: 2 件');
});
