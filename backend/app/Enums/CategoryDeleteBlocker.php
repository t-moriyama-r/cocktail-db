<?php

namespace App\Enums;

/**
 * カテゴリ木のノードを消せない理由(docs/schema.md の「カテゴリを消すとき」。#91)。
 *
 * 管理画面(#121)が、どれがあるから消せないのかを出せるよう、理由ごとに分けて持つ。
 */
enum CategoryDeleteBlocker: string
{
    /** 子のノードがある(消せるのは葉だけ) */
    case Children = 'children';

    /** このノードに所属する銘柄がある */
    case Brands = 'brands';

    /** カクテルの材料に使われている */
    case Ingredients = 'ingredients';

    /** カクテルのベースに使われている */
    case BaseCocktails = 'base_cocktails';

    /** このノードのアフィリエイトの行がある */
    case Affiliates = 'affiliates';

    /**
     * 管理画面にそのまま出す、消せない理由の文(#121)。
     */
    public function message(): string
    {
        return match ($this) {
            self::Children => '子のノードがあります',
            self::Brands => 'このノードの銘柄があります',
            self::Ingredients => 'カクテルの材料に使われています',
            self::BaseCocktails => 'カクテルのベースに使われています',
            self::Affiliates => 'このノードのアフィリエイトがあります',
        };
    }
}
