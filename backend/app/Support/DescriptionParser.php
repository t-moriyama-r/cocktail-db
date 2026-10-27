<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\Dictionary;
use Generator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 説明文(description・note)を、画面がそのまま描ける区切りの並びにする(#286。決めたことは
 * docs/decisions/description-parts.md)。旧の Markup::generateDiscriptionArray を、新しい記法(#285)で移したもの。
 *
 * 区切りは、文字の区切り `{type: "text", text}` と、リンクの区切り `{type, id, text, has_youtube}` の 2 つ。
 * リンクの区切りの text はリンク先の名前、has_youtube はリンク先に動画があるか。文字は HTML にせず、改行も改行のまま返す。
 *
 * @phpstan-type TextPart array{type: 'text', text: string}
 * @phpstan-type LinkPart array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}
 * @phpstan-type Target array{id: int, name: string, has_youtube: bool, linkable: bool}
 */
final class DescriptionParser
{
    /**
     * 参照の記法。保存のとき(DescriptionMarkup)は大文字の記法も書いたまま残すので、大文字も読む。
     */
    private const REFERENCE_PATTERN = '/@([nNcCbBdD])([1-9][0-9]*)@/';

    /**
     * int に収まる ID の桁数。超える記法は、指す先が見つからない参照と同じく取り除く。
     */
    private const MAX_ID_DIGITS = 18;

    /**
     * 説明文を区切りの並びにする。null と空の文字は、旧と同じく null。
     * 指す先の見つからない参照は記法を取り除き、下書きのカクテルの参照は名前だけの文字にする。
     * 続いた文字の区切りは 1 つにつなげ、空の文字の区切りは入れない。
     *
     * @return list<TextPart|LinkPart>|null
     */
    public function parse(?string $text): ?array
    {
        if ($text === null || $text === '') {
            return null;
        }

        // [文字, 英字, ID, 文字, 英字, ID, …, 文字] に分ける
        $pieces = preg_split(self::REFERENCE_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false) {
            return [['type' => 'text', 'text' => $text]];
        }

        return iterator_to_array($this->parts($pieces, $this->targets($pieces)), false);
    }

    /**
     * 分けた説明文から、区切りを順に出す。
     *
     * @param  list<string>  $pieces
     * @param  array<string, array<int, Target>>  $targets
     * @return Generator<int, TextPart|LinkPart>
     */
    private function parts(array $pieces, array $targets): Generator
    {
        $buffer = '';
        foreach ($pieces as $i => $piece) {
            if ($i % 3 === 0) {
                $buffer .= $piece;

                continue;
            }
            if ($i % 3 === 2) {
                continue;
            }
            $kind = strtolower($piece);
            $target = $targets[$kind][$pieces[$i + 1]] ?? null;
            if ($target === null) {
                continue;
            }
            if (! $target['linkable']) {
                // 下書きのカクテルは公開の詳細が 404 になるので、リンクにしない
                $buffer .= $target['name'];

                continue;
            }
            if ($buffer !== '') {
                yield $this->textPart($buffer);
                $buffer = '';
            }
            yield $this->linkPart($kind, $target);
        }
        if ($buffer !== '') {
            yield $this->textPart($buffer);
        }
    }

    /**
     * @return TextPart
     */
    private function textPart(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * @param  Target  $target
     * @return LinkPart
     */
    private function linkPart(string $kind, array $target): array
    {
        return [
            'type' => match ($kind) {
                'n' => 'node',
                'c' => 'cocktail',
                'b' => 'brand',
                default => 'dictionary',
            },
            'id' => $target['id'],
            'text' => $target['name'],
            'has_youtube' => $target['has_youtube'],
        ];
    }

    /**
     * 記法が指す先を、種類ごとに 1 回の問い合わせで引く。
     *
     * @param  list<string>  $pieces
     * @return array<string, array<int, Target>> 英字(小文字) => [ID => 指す先]
     */
    private function targets(array $pieces): array
    {
        $ids = [];
        for ($i = 1; $i < count($pieces); $i += 3) {
            $id = $pieces[$i + 1];
            if (strlen($id) <= self::MAX_ID_DIGITS) {
                $ids[strtolower($pieces[$i])][] = (int) $id;
            }
        }

        $targets = [];
        foreach ($ids as $kind => $kindIds) {
            $targets[$kind] = [];
            foreach ($this->find($kind, array_values(array_unique($kindIds))) as $model) {
                $targets[$kind][$model->id] = [
                    'id' => $model->id,
                    'name' => $model->name,
                    'has_youtube' => $model->youtube !== null && $model->youtube !== '',
                    'linkable' => ! ($model instanceof Cocktail && $model->is_draft),
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Category>|Collection<int, Cocktail>|Collection<int, Brand>|Collection<int, Dictionary>
     */
    private function find(string $kind, array $ids): Collection
    {
        return match ($kind) {
            'n' => Category::query()->whereKey($ids)->get(['id', 'name', 'youtube']),
            'c' => Cocktail::query()->whereKey($ids)->get(['id', 'name', 'youtube', 'is_draft']),
            'b' => Brand::query()->whereKey($ids)->get(['id', 'name', 'youtube']),
            default => Dictionary::query()->whereKey($ids)->get(['id', 'name', 'youtube']),
        };
    }
}
