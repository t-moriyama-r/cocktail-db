<?php

namespace App\Http\Controllers;

use App\Services\LegacyImport\LegacyCategoryNodes;
use App\Support\LegacyCategoryUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * 旧の管理画面のカテゴリ・ミドルの編集の URL(/admin/edit/Category/{旧ID}・/admin/edit/Middle/{旧ID})を、
 * 移した先のノードの編集フォーム(/admin/categories/{新ID})へ 302 で移す(#817)。
 *
 * 旧 ID と新しいノードの対応は、旧の詳細の URL の転送(#78)と同じく legacy_id_maps で引く(LegacyCategoryUrl)。
 * 対応が無い・対応先のノードが消えた ID は、推測で移さず、これまでどおり SPA の HTML を返す(画面は管理画面の 404 の画面)。
 * 決めたことと旧と変えたところは docs/decisions/legacy-admin-urls.md。
 */
class LegacyAdminCategoryUrlController extends Controller
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
     * 移った先にクエリは持ち越さない(docs/decisions/legacy-admin-urls.md の決まったこと 2)。
     * Location は、ほかの旧の管理画面の URL の転送(RedirectController)と同じく、ホストを付けないパスにする。
     */
    private function redirect(string $legacyTable, string $id): RedirectResponse|Response
    {
        $nodeId = LegacyCategoryUrl::nodeId($legacyTable, $id);

        return $nodeId === null
            ? response()->view('spa')
            : new RedirectResponse('/admin/categories/'.$nodeId, 302);
    }
}
