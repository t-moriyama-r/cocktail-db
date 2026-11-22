<?php

namespace App\Support;

/**
 * SPA の HTML に入れる OGP(`og:*`)と Twitter カード(`twitter:*`)の値(#112)。
 * SNS のクローラーは JS を動かさないので、サーバーが返す HTML に入れる。
 *
 * 詳細ページ(カクテル・カテゴリ・銘柄・用語)はページごとの値、それ以外のページ・無い ID の 404 はサイト共通の値。
 * 決めたことと旧との差分は docs/decisions/ogp.md。
 */
final class SocialCard
{
    /**
     * og:title に付けるサイト名。旧の og:title(サイト共通の値と、詳細ページの `名前 | サイト名`)と同じ。
     * `<title>` のサイト名(PageTitle::SITE_TITLE)とは違う(旧も違った)。
     */
    public const SITE_NAME = '宅飲みカクテルデータベース';

    /**
     * 画像の無いページの og:image。public の直下に置いた画像のパス。
     */
    public const DEFAULT_IMAGE_PATH = '/og-default.png';

    /**
     * サイト共通の値。旧の画面の HTML(welcome.blade.php)と同じく、og:url はトップを指し、カードは大きい画像の形にする。
     *
     * @return array{url: string, title: string, description: string, image: string, card: string}
     */
    public static function site(): array
    {
        return [
            'url' => self::appUrl().'/',
            'title' => self::SITE_NAME,
            'description' => MetaDescription::SITE_DESCRIPTION,
            'image' => self::appUrl().self::DEFAULT_IMAGE_PATH,
            'card' => 'summary_large_image',
        ];
    }

    /**
     * 詳細ページの値。旧の SNS 向けの HTML(SNSCardController)と同じく、og:title は `名前 | サイト名`、カードは小さい画像の形にする。
     *
     * @param  string  $description  MetaDescription::make で作った文(`<meta name="description">` と同じ切り方)
     * @param  string|null  $image  画像の列の値(public ディスクのファイル名か、外部の URL)
     * @param  string  $url  そのページの canonical
     * @return array{url: string, title: string, description: string, image: string, card: string}
     */
    public static function detail(string $name, string $description, ?string $image, string $url): array
    {
        return [
            'url' => $url,
            'title' => $name.' | '.self::SITE_NAME,
            'description' => $description,
            'image' => self::imageUrl($image),
            'card' => 'summary',
        ];
    }

    /**
     * 画像の列の値を、SNS が取りに来られる絶対 URL にする。
     * 外部の URL はそのまま、ファイル名は `/storage/<ファイル名>`(AdminImageStore の置き場所)、空なら既定の画像。
     */
    public static function imageUrl(?string $image): string
    {
        if ($image === null || trim($image) === '') {
            return self::appUrl().self::DEFAULT_IMAGE_PATH;
        }
        if (PublicDiskImage::isExternal($image)) {
            return $image;
        }

        $path = implode('/', array_map(rawurlencode(...), explode('/', ltrim($image, '/'))));

        return self::appUrl().'/storage/'.$path;
    }

    private static function appUrl(): string
    {
        return rtrim(config()->string('app.url'), '/');
    }
}
