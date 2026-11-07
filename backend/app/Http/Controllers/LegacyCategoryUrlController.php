<?php

namespace App\Http\Controllers;

use App\Services\LegacyImport\LegacyCategoryNodes;
use App\Support\LegacyCategoryUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * 旧のカテゴリ・ミドルの詳細の URL(/detail/category/{旧ID}・/detail/middle/{旧ID})を、
 * カテゴリ木に移した先のノードの詳細(/category/{新ID})へ 301 で移す(#78)。
 *
 * 旧 ID と新しいノードの対応は、カテゴリ木を移したとき(#29)の legacy_id_maps で引く(LegacyCategoryUrl)。
 * 対応が無い・数字でない・対応先のノードが消えた ID は、推測で転送せず、SPA の HTML を 404 の状態で返す
 * (画面は #97 の「ページが見つかりません」)。旧と変えたところは docs/decisions/legacy-urls.md の「旧との差分」。
 */
class LegacyCategoryUrlController extends Controller
{
    public function category(string $id): RedirectResponse|Response
    {
        return $this->redirect(LegacyCategoryNodes::LEGACY_CATEGORY, $id);
    }

    public function middle(string $id): RedirectResponse|Response
    {
        return $this->redirect(LegacyCategoryNodes::LEGACY_MIDDLE, $id);
    }

    /**
     * 移った先にクエリは持ち越さない(旧のカテゴリ・ミドルの詳細はクエリを使っていない)。
     */
    private function redirect(string $legacyTable, string $id): RedirectResponse|Response
    {
        $path = LegacyCategoryUrl::nodePath($legacyTable, $id);

        return $path === null
            ? response()->view('spa', [], 404)
            : redirect($path, 301);
    }
}
