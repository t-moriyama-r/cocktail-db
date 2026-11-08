<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Cocktail;
use App\Services\LegacyImport\LegacyCategoryNodes;
use App\Support\LegacyCategoryUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * 旧の SNS カードの URL(/card/{command}/{id})を、同じものの詳細ページへ 301 で移す(#113)。
 *
 * 旧は SNS のクローラーをこの URL に回し、OGP だけの HTML を返していた(旧 SNSCardController@display)。
 * 新は詳細ページの HTML が OGP を持つ(#112)ので、詳細ページへ移す。
 *
 * - command は旧が受けていた表記だけを受ける(小文字と、先頭だけ大文字)。ほかの表記・知らない語は 404
 * - カクテル・銘柄は ID が変わらないので、同じ ID の詳細へ移す。下書きのカクテルは、移った先が 404 になる(#111)ので 404
 * - カテゴリ・ミドルはノードの ID を振り直したので、#78 と同じ付け替え(LegacyCategoryUrl)で /category/{新ID} へ移す
 * - 無い ID・数字でない ID は、推測で転送せず、SPA の HTML を 404 の状態で返す(画面は #97 の「ページが見つかりません」)
 * - 移った先にクエリは持ち越さない
 *
 * 旧と変えたところは docs/decisions/legacy-urls.md の「旧との差分」。
 */
class LegacyCardUrlController extends Controller
{
    public function __invoke(string $command, string $id): RedirectResponse|Response
    {
        $path = match ($command) {
            'cocktail', 'Cocktail' => $this->cocktailPath($id),
            'brand', 'Brand' => $this->brandPath($id),
            'category', 'Category' => LegacyCategoryUrl::nodePath(LegacyCategoryNodes::LEGACY_CATEGORY, $id),
            'middle', 'Middle' => LegacyCategoryUrl::nodePath(LegacyCategoryNodes::LEGACY_MIDDLE, $id),
            default => null,
        };

        return $path === null
            ? response()->view('spa', [], 404)
            : redirect($path, 301);
    }

    private function cocktailPath(string $id): ?string
    {
        // int に収まらない桁の ID は、int にすると上限に丸まるので、どの行にも当たらない(DetailPageController と同じ)
        return ctype_digit($id) && Cocktail::query()->published()->whereKey((int) $id)->exists()
            ? '/detail/cocktail/'.(int) $id
            : null;
    }

    private function brandPath(string $id): ?string
    {
        return ctype_digit($id) && Brand::query()->whereKey((int) $id)->exists()
            ? '/detail/brand/'.(int) $id
            : null;
    }
}
