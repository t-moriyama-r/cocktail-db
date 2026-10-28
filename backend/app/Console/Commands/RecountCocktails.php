<?php

namespace App\Console\Commands;

use App\Models\Cocktail;
use App\Services\RecipeCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * すべてのカクテルの材料の数(recipe_count)を数え直す(#74)。
 *
 * 旧は管理者が URL(sys/genrec。SystemController::insertRecipeCols)を開くと、全カクテルを数え直して Eloquent の
 * save で書いていた。数え方と書き込みは RecipeCounter(#156。docs/decisions/recipe-count.md)だけが持つので、
 * ここは数え直す対象を渡すだけにする。
 *
 * 旧の save は、値が変わったカクテルだけ更新日時を実行した時刻にしていた(変わらなければ書かない)。RecipeCounter は
 * 更新日時を変えない(移行で旧の更新日時を残すため)ので、旧と同じになるよう、値が変わったカクテルの更新日時をここで今にする。
 */
final class RecountCocktails extends Command
{
    protected $signature = 'cocktails:recount';

    protected $description = 'すべてのカクテルの材料の数(recipe_count)を数え直す。値が変わったカクテルは更新日時を今にする(旧の sys/genrec)';

    public function handle(RecipeCounter $counter): int
    {
        [$total, $changed] = DB::transaction(function () use ($counter): array {
            $before = $this->storedCounts();
            $counter->recount(array_keys($before));

            $changedIds = [];
            foreach ($this->storedCounts() as $id => $count) {
                if ($count !== $before[$id]) {
                    $changedIds[] = $id;
                }
            }
            if ($changedIds !== []) {
                Cocktail::query()->whereKey($changedIds)->touch();
            }

            return [count($before), count($changedIds)];
        });

        $this->info("材料の数を数え直した: {$total} 件(値が変わった: {$changed} 件)");

        return self::SUCCESS;
    }

    /**
     * カクテルの ID → いまの列 recipe_count の値。
     *
     * @return array<int, int>
     */
    private function storedCounts(): array
    {
        $counts = [];
        foreach (Cocktail::query()->orderBy('id')->get(['id', 'recipe_count']) as $cocktail) {
            $counts[$cocktail->id] = $cocktail->recipe_count;
        }

        return $counts;
    }
}
