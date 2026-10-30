<?php

namespace App\Services\LegacySearchCompare;

use InvalidArgumentException;

/**
 * 突き合わせる条件の 1 組(tests/Fixtures/search-cases.json の 1 要素。#169)。
 *
 * 旧の条件(searchMethod・Category・Middle・Method・isEasily)を、旧 DB の ID そのものか、旧 DB から引く選び方で書く。
 * 選び方で書くと、テストの旧データ(LegacyFixture)でも本番の旧 DB でも、同じ形の条件を流せる。
 *
 * - categories: 旧のカテゴリの ID の並びか、選び方のオブジェクト。オブジェクトのキーはどれも省略でき、すべてを満たすカテゴリを
 *   ID の昇順で選ぶ(空のオブジェクトなら、すべてのカテゴリ)
 *   - kind: 種別の ID の並び。この種別のカテゴリだけ
 *   - not_kind: 種別の ID の並び。この種別でないカテゴリだけ
 *   - has_middles: true なら子のミドルのあるカテゴリだけ、false なら無いカテゴリだけ
 *   - optional: true なら、任意の材料の行(下書きでない、あるカクテルの)に使われたカテゴリだけ
 *   - limit: 先頭からこの数だけ
 * - middles: 旧のミドルの ID の並びか、次の文字列
 *   - children: 選んだカテゴリの子のミドルをすべて(旧の画面でカテゴリにチェックを入れたとき)
 *   - first_child: 選んだカテゴリごとに、子のミドルのうち ID のいちばん小さいもの(子が 1 つだけならそれ)
 *   - none: 空の配列(旧の画面で子のミドルをすべて外したとき)
 *   - any: 999999(旧のアフィリエイトの部品が送る「ミドルを問わない」)
 * - methods: 旧の技法の ID の並びか、"all"(すべての技法。旧の画面の既定)
 * - easy: かんたん検索(isEasily)
 */
final readonly class SearchCase
{
    public const OR = 'or';

    public const AND = 'and';

    public const HAVING = 'having';

    public const MODES = [self::OR, self::AND, self::HAVING];

    public const MIDDLE_SELECTORS = ['children', 'first_child', 'none', 'any'];

    private const CATEGORY_KEYS = ['kind', 'not_kind', 'has_middles', 'optional', 'limit'];

    /**
     * @param  array<array-key, mixed>  $categories  ID の並び(list<int>)か選び方のオブジェクト
     * @param  list<int>|string  $middles
     * @param  list<int>|null  $methods  null ならすべての技法
     * @param  list<string>  $tags
     */
    private function __construct(
        public string $id,
        public string $description,
        public array $tags,
        public string $mode,
        public array $categories,
        public array|string $middles,
        public ?array $methods,
        public bool $easy,
    ) {}

    /**
     * JSON の 1 要素を読む。形が合わなければ、どの組のどのキーかを添えて止める。
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(mixed $raw, int $index): self
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException("{$index} 番目の組がオブジェクトでない");
        }
        $id = $raw['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException("{$index} 番目の組に id が無い");
        }
        $fail = fn (string $message) => new InvalidArgumentException("組 {$id}: {$message}");

        $mode = $raw['mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw $fail('mode は or・and・having のどれか');
        }

        $description = $raw['description'] ?? '';
        $tags = $raw['tags'] ?? [];
        if (! is_string($description) || ! is_array($tags) || ! array_is_list($tags)
            || array_filter($tags, fn (mixed $tag): bool => ! is_string($tag)) !== []) {
            throw $fail('description は文字列、tags は文字列の並び');
        }
        /** @var list<string> $tags */
        $categories = $raw['categories'] ?? null;
        if (! is_array($categories) || ($categories !== [] && ! array_is_list($categories) && array_diff(array_keys($categories), self::CATEGORY_KEYS) !== [])) {
            throw $fail('categories は ID の並びか、'.implode('・', self::CATEGORY_KEYS).' をキーにしたオブジェクト');
        }
        if (array_is_list($categories) && $categories !== []) {
            $categories = self::ids($categories) ?? throw $fail('categories の ID は整数');
        }

        $middles = $raw['middles'] ?? null;
        if (is_string($middles)) {
            if (! in_array($middles, self::MIDDLE_SELECTORS, true)) {
                throw $fail('middles の選び方は '.implode('・', self::MIDDLE_SELECTORS).' のどれか');
            }
        } elseif (is_array($middles) && array_is_list($middles)) {
            $middles = self::ids($middles) ?? throw $fail('middles の ID は整数');
        } else {
            throw $fail('middles は ID の並びか選び方の文字列');
        }

        $methods = $raw['methods'] ?? 'all';
        if ($methods === 'all') {
            $methods = null;
        } elseif (is_array($methods) && array_is_list($methods)) {
            $methods = self::ids($methods) ?? throw $fail('methods の ID は整数');
        } else {
            throw $fail('methods は ID の並びか "all"');
        }

        $easy = $raw['easy'] ?? false;
        if (! is_bool($easy)) {
            throw $fail('easy は真偽値');
        }

        return new self($id, $description, $tags, $mode, $categories, $middles, $methods, $easy);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<int>|null 整数でない値があれば null
     */
    private static function ids(array $values): ?array
    {
        $ids = [];
        foreach ($values as $value) {
            if (! is_int($value)) {
                return null;
            }
            $ids[] = $value;
        }

        return $ids;
    }
}
