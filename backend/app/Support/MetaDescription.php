<?php

namespace App\Support;

/**
 * 説明文(description)から、HTML の `<meta name="description">` に入れる文を作る(#111)。
 * #112 の `og:description` も同じ切り方にするので、どちらもこれを通す。
 *
 * 参照の記法(`@c12@` など)は指す先の名前にし(DescriptionParser と同じ。指す先の無い参照は除く)、
 * タグ(`<b>` など)を除き、改行を空白にする。120 字を超えたら 119 字に「…」を足す。
 * 説明文が空なら、サイト共通の文(旧の og:description と同じ)にする。
 */
final class MetaDescription
{
    /**
     * 説明文が空のときに入れる、サイト共通の文(旧の og:description)。
     */
    public const SITE_DESCRIPTION = '読んで楽しい、を目指したデータベース。';

    /**
     * 入れる文の上限の字数。超えたら、上限から 1 字減らして「…」を足す。
     */
    private const MAX_LENGTH = 120;

    public function __construct(private readonly DescriptionParser $parser) {}

    public function make(?string $description): string
    {
        $segments = $this->parser->parse($description) ?? [];
        $text = strip_tags(implode('', array_column($segments, 'text')));
        $text = trim((string) preg_replace('/\R/u', ' ', $text));

        if ($text === '') {
            return self::SITE_DESCRIPTION;
        }
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_LENGTH - 1).'…';
    }
}
