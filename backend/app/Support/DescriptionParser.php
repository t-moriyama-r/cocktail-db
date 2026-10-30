<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;

/**
 * 説明文(description・note)を、画面がそのまま描ける区切りの並びにする(#286。決めたことは docs/decisions/description-segments.md)。
 *
 * 旧の Markup::generateDiscriptionArray を、新しい記法(#285 の `@n<ノードの ID>@`・`@c`・`@b`・`@d`)で移したもの。
 * 文字の区切り `{type: "text", text}` と、リンクの区切り `{type: "node"|"cocktail"|"brand"|"dictionary", id, text, has_youtube}` を
 * 説明文の順に並べる。リンクの `text` は指す先の名前、`has_youtube` は指す先に動画があるか。
 *
 * @phpstan-type TextSegment array{type: 'text', text: string}
 * @phpstan-type LinkSegment array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}
 */
final class DescriptionParser
{
    /**
     * ID の記法。種類は n・c・b・d(大文字も同じ意味)。ID は経路(routes/api.php)と同じく int に収まる 18 桁まで。
     * 説明文の参照が指す先があるかを data:verify(#285)も同じ形で調べるので、公開する。
     */
    public const REFERENCE_PATTERN = '/@([nNcCbBdD])([1-9][0-9]{0,17})@/';

    /**
     * 記法の種類と、応答の type。
     */
    private const TYPES = ['n' => 'node', 'c' => 'cocktail', 'b' => 'brand', 'd' => 'dictionary'];

    /**
     * 説明文を区切りの並びにする。null・空の文字列は null(旧と同じ)。
     *
     * 改行は HTML(旧の nl2br の `<br />`)にせず、文字の改行のまま返す。指す先が無い参照は取り除き、
     * 下書きのカクテルを指す参照は名前だけの文字の区切りにする。隣り合う文字の区切りは 1 つにまとめ、空の文字の区切りは置かない。
     *
     * @return list<TextSegment|LinkSegment>|null
     */
    public function parse(?string $description): ?array
    {
        if ($description === null || $description === '') {
            return null;
        }

        preg_match_all(self::REFERENCE_PATTERN, $description, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $targets = $this->findTargets($matches);

        $segments = [];
        $offset = 0;
        foreach ($matches as $m) {
            [$whole, $start] = $m[0];
            $this->appendText($segments, substr($description, $offset, $start - $offset));
            $offset = $start + strlen($whole);

            $kind = strtolower($m[1][0]);
            $id = (int) $m[2][0];
            $target = $targets[$kind][$id] ?? null;
            if ($target === null) {
                continue;
            }
            if ($target['is_draft']) {
                $this->appendText($segments, $target['name']);

                continue;
            }
            $segments[] = [
                'type' => self::TYPES[$kind],
                'id' => $id,
                'text' => $target['name'],
                'has_youtube' => $target['has_youtube'],
            ];
        }
        $this->appendText($segments, substr($description, $offset));

        return $segments;
    }

    /**
     * 記法が指すものを、種類ごとに 1 回の問い合わせで引く。下書きを持つのはカクテルだけ。
     *
     * @param  array<int, array<int, array{0: string, 1: int}>>  $matches
     * @return array<string, array<int, array{name: string, has_youtube: bool, is_draft: bool}>>
     */
    private function findTargets(array $matches): array
    {
        $ids = [];
        foreach ($matches as $m) {
            $ids[strtolower($m[1][0])][] = (int) $m[2][0];
        }

        $targets = [];
        foreach ($ids as $kind => $kindIds) {
            $kindIds = array_values(array_unique($kindIds));
            $models = match ($kind) {
                'n' => Category::query()->whereKey($kindIds)->get(),
                'c' => Cocktail::query()->whereKey($kindIds)->get(),
                'b' => Brand::query()->whereKey($kindIds)->get(),
                default => Dictionary::query()->whereKey($kindIds)->get(),
            };
            foreach ($models as $model) {
                $targets[$kind][$model->id] = [
                    'name' => $model->name,
                    'has_youtube' => ! in_array($model->youtube, [null, ''], true),
                    'is_draft' => $model instanceof Cocktail && $model->is_draft,
                ];
            }
        }

        return $targets;
    }

    /**
     * 文字の区切りを足す。空なら足さず、前も文字の区切りならそこにつなげる。
     *
     * @param  list<TextSegment|LinkSegment>  $segments
     *
     * @param-out list<TextSegment|LinkSegment>  $segments
     */
    private function appendText(array &$segments, string $text): void
    {
        if ($text === '') {
            return;
        }
        $last = array_key_last($segments);
        if ($last !== null && $segments[$last]['type'] === 'text') {
            $segments[$last] = ['type' => 'text', 'text' => $segments[$last]['text'].$text];

            return;
        }
        $segments[] = ['type' => 'text', 'text' => $text];
    }
}
