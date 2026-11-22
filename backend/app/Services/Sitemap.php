<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use XMLWriter;

/**
 * sitemap.xml の中身(#114)。決めたことと旧との差分は docs/decisions/sitemap.md。
 *
 * 載せるのは詳細ページ(下書きを除くカクテル・カテゴリ・銘柄・用語)の URL。
 * 並びはカクテル → カテゴリ → 銘柄 → 用語、それぞれ ID の昇順。
 * 1 ファイルに載せられる URL はサイトマップの決まりで 50,000 件まで。超えたら 50,000 件ずつのファイルに分け、
 * sitemap.xml はそれらを並べたサイトマップインデックスにする。
 */
class Sitemap
{
    /** 1 ファイルに載せる URL の上限(sitemaps.org の決まり) */
    public const MAX_URLS = 50000;

    private const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * 種類ごとの、詳細ページのパスの頭と、引く表・絞り込み。並びはこの順。
     * パスは routes/web.php の詳細ページのルート(DetailPageController)と同じ。
     *
     * @var list<array{table: string, path: string, published: bool}>
     */
    private const KINDS = [
        ['table' => 'cocktails', 'path' => '/detail/cocktail/', 'published' => true],
        ['table' => 'categories', 'path' => '/category/', 'published' => false],
        ['table' => 'brands', 'path' => '/detail/brand/', 'published' => false],
        ['table' => 'dictionaries', 'path' => '/dictionary/', 'published' => false],
    ];

    /**
     * 載せる URL の総数。
     */
    public function count(): int
    {
        return array_sum(array_map(fn (array $kind): int => $this->query($kind)->count(), self::KINDS));
    }

    /**
     * 分けたときのファイルの数。分けないとき(50,000 件以下)は 1。
     */
    public function pageCount(): int
    {
        return max(1, intdiv($this->count() + self::MAX_URLS - 1, self::MAX_URLS));
    }

    /**
     * 分けるか(50,000 件を超えるか)。
     */
    public function isSplit(): bool
    {
        return $this->count() > self::MAX_URLS;
    }

    /**
     * $page 番目(1 から)のファイルの urlset。分けないときは 1 番目が全部になる。
     */
    public function urlset(int $page): string
    {
        $xml = $this->start('urlset');

        $offset = ($page - 1) * self::MAX_URLS;
        $remaining = self::MAX_URLS;
        foreach (self::KINDS as $kind) {
            if ($remaining === 0) {
                break;
            }
            $count = $this->query($kind)->count();
            if ($offset >= $count) {
                $offset -= $count;

                continue;
            }

            $rows = $this->query($kind)
                ->select(['id', 'updated_at'])
                ->orderBy('id')
                ->offset($offset)
                ->limit($remaining)
                ->cursor();
            foreach ($rows as $row) {
                /** @var object{id: int|string, updated_at: string|null} $row */
                $xml->startElement('url');
                $xml->writeElement('loc', $this->url($kind['path'].$row->id));
                if ($row->updated_at !== null) {
                    $xml->writeElement('lastmod', $this->lastmod($row->updated_at));
                }
                $xml->endElement();
                $remaining--;
            }
            $offset = 0;
        }

        return $this->finish($xml);
    }

    /**
     * 分けたファイル(/sitemap-1.xml …)を並べたサイトマップインデックス。
     */
    public function index(): string
    {
        $xml = $this->start('sitemapindex');
        $pages = $this->pageCount();
        for ($page = 1; $page <= $pages; $page++) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $this->url("/sitemap-{$page}.xml"));
            $xml->endElement();
        }

        return $this->finish($xml);
    }

    /**
     * @param  array{table: string, path: string, published: bool}  $kind
     */
    private function query(array $kind): Builder
    {
        $query = DB::table($kind['table']);

        // 下書きのカクテルは公開の画面に出さない(Cocktail::published と同じ条件)
        return $kind['published'] ? $query->where('is_draft', false) : $query;
    }

    /**
     * APP_URL とパスをつないだ URL(詳細ページの canonical と同じ形)。
     */
    private function url(string $path): string
    {
        return rtrim(config()->string('app.url'), '/').$path;
    }

    /**
     * 更新日時(DB はアプリの時間帯で持つ)を W3C の日時の形にする。
     */
    private function lastmod(string $updatedAt): string
    {
        return CarbonImmutable::parse($updatedAt, config()->string('app.timezone'))->toAtomString();
    }

    private function start(string $root): XMLWriter
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNs(null, $root, self::NAMESPACE);

        return $xml;
    }

    private function finish(XMLWriter $xml): string
    {
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
