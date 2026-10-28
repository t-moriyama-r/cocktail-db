<?php

namespace App\Console\Commands;

use App\Models\Cocktail;
use Illuminate\Console\Command;

/**
 * 動画があって公開日時(youtube_created_at)の無いカクテルに、そのカクテルの更新日時を公開日時として入れる(#74)。
 *
 * 旧は管理者が URL(sys/gen。SystemController::insertYoutubeDatetime)を開くと、これを行っていた。YouTube には
 * 問い合わせない(YouTube Data API のキーは要らない)。旧は公開日時を、トップの「最新の動画」を選ぶ並べ替えに使っていた。
 *
 * 旧と同じにするもの:
 * - 対象は動画の列が null でないカクテル(空文字も入る)。銘柄・ノードは対象にしない
 * - Eloquent の save で書くので、書いたカクテルの更新日時は実行した時刻になる。更新日時が null なら何も書かない
 */
final class FetchYoutubeDates extends Command
{
    protected $signature = 'youtube:fetch-dates';

    protected $description = '動画があって公開日時の無いカクテルに、更新日時を公開日時として入れる(旧の sys/gen。YouTube には問い合わせない)';

    public function handle(): int
    {
        $filled = 0;
        $cocktails = Cocktail::query()->whereNotNull('youtube')->whereNull('youtube_created_at')->lazyById();
        foreach ($cocktails as $cocktail) {
            $cocktail->youtube_created_at = $cocktail->updated_at;
            if ($cocktail->isDirty()) {
                $cocktail->save();
                $filled++;
            }
        }

        $this->info("動画の公開日時を入れた: {$filled} 件");

        return self::SUCCESS;
    }
}
