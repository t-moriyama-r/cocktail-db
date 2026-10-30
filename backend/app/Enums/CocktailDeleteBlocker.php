<?php

namespace App\Enums;

/**
 * カクテルを消せない理由(#579)。説明文(description・カクテルの note)から `@c<ID>@` で参照されているとき、参照している側の種類ごとに分ける。
 *
 * 説明文の参照は外部キーではないので、消しても DB には拒まれず、参照が残って data:verify(#285 の 8)が失敗する。
 * 用語辞典の削除(#315。DictionaryDeleteBlocker)と同じく、消す前に調べて理由を返す。種類は data:verify が説明文を調べる表と同じ。
 */
enum CocktailDeleteBlocker: string
{
    /** ほかのカクテルの説明・補足から参照されている(cocktails.description・note) */
    case Cocktail = 'cocktail';

    /** ノードの説明から参照されている(categories.description) */
    case Node = 'node';

    /** 技法の説明から参照されている(methods.description) */
    case Method = 'method';

    /** 銘柄の説明から参照されている(brands.description) */
    case Brand = 'brand';

    /** 用語の説明から参照されている(dictionaries.description) */
    case Dictionary = 'dictionary';

    /**
     * 管理画面にそのまま出す、消せない理由の文。
     */
    public function message(): string
    {
        return match ($this) {
            self::Cocktail => 'ほかのカクテルの説明から参照されています',
            self::Node => 'ノードの説明から参照されています',
            self::Method => '技法の説明から参照されています',
            self::Brand => '銘柄の説明から参照されています',
            self::Dictionary => '用語の説明から参照されています',
        };
    }

    /**
     * 参照している側の表と、調べる説明文の列。
     *
     * @return array{string, list<string>}
     */
    public function source(): array
    {
        return match ($this) {
            self::Cocktail => ['cocktails', ['description', 'note']],
            self::Node => ['categories', ['description']],
            self::Method => ['methods', ['description']],
            self::Brand => ['brands', ['description']],
            self::Dictionary => ['dictionaries', ['description']],
        };
    }
}
