<?php

namespace App\Http\Controllers;

use App\Services\Sitemap;
use Illuminate\Http\Response;

/**
 * sitemap.xml・分けたサイトマップ(/sitemap-{n}.xml)・robots.txt を返す(#114)。
 * 決めたことと旧との差分は docs/decisions/sitemap.md。
 */
class SitemapController extends Controller
{
    public function __construct(private readonly Sitemap $sitemap) {}

    /**
     * 50,000 件以下なら URL を全部載せた urlset、超えたら分けたファイルを並べたサイトマップインデックス。
     */
    public function index(): Response
    {
        return $this->xml($this->sitemap->isSplit() ? $this->sitemap->index() : $this->sitemap->urlset(1));
    }

    /**
     * 分けたときの $page 番目のファイル。分けていないとき・ファイルの数を超えた番号は 404。
     */
    public function page(string $page): Response
    {
        $number = (int) $page;
        abort_unless($this->sitemap->isSplit() && $number <= $this->sitemap->pageCount(), 404);

        return $this->xml($this->sitemap->urlset($number));
    }

    /**
     * どのページもクロールしてよい(旧の robots.txt と同じ)。sitemap.xml の場所を APP_URL で書き足す。
     */
    public function robots(): Response
    {
        $sitemap = rtrim(config()->string('app.url'), '/').'/sitemap.xml';

        return response("User-agent: *\nDisallow:\n\nSitemap: {$sitemap}\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
