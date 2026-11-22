<?php

namespace App\Enums;

/**
 * カテゴリ木のノードを、説明文からの参照で消せない理由(#629)。説明文(description・カクテルの note)から `@n<ID>@` で
 * 参照されているとき、参照している側の種類ごとに分ける。種類はカクテルの削除(CocktailDeleteBlocker。#579)と同じ。
 *
 * 子・銘柄・材料などの木の形・外部キーの理由(CategoryDeleteBlocker)は CategoryTree::deleteLeaf が調べる。説明文の参照は
 * 外部キーではないので木は調べず、管理画面の削除(AdminCategoryWriter)が調べる。消すと参照が残り、data:verify(#285 の 8)が失敗する。
 */
enum CategoryReferenceBlocker: string
{
    /** カクテルの説明・補足から参照されている(cocktails.description・note) */
    case Cocktail = 'referenced_by_cocktail';

    /** ほかのノードの説明から参照されている(categories.description) */
    case Node = 'referenced_by_node';

    /** 技法の説明から参照されている(methods.description) */
    case Method = 'referenced_by_method';

    /** 銘柄の説明から参照されている(brands.description) */
    case Brand = 'referenced_by_brand';

    /** 用語の説明から参照されている(dictionaries.description) */
    case Dictionary = 'referenced_by_dictionary';

    /**
     * 管理画面にそのまま出す、消せない理由の文。
     */
    public function message(): string
    {
        return match ($this) {
            self::Cocktail => 'カクテルの説明から参照されています',
            self::Node => 'ほかのノードの説明から参照されています',
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
