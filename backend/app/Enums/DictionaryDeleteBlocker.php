<?php

namespace App\Enums;

/**
 * 用語辞典の用語を消せない理由(#315)。
 *
 * 管理画面(#66)が、どれがあるから消せないのかを出せるよう、理由ごとに分けて持つ。どちらも外部キーが RESTRICT
 * (docs/schema.md の「外部キーと削除」)なので、消そうとすると DB に拒まれる。その前に調べて理由を返す。
 */
enum DictionaryDeleteBlocker: string
{
    /** カクテルのグラスに使われている(cocktails.glass_dictionary_id) */
    case Glass = 'glass';

    /** 技法を説明する用語になっている(methods.dictionary_id。#40) */
    case Method = 'method';

    /**
     * 管理画面にそのまま出す、消せない理由の文。
     */
    public function message(): string
    {
        return match ($this) {
            self::Glass => 'カクテルのグラスに使われています',
            self::Method => '技法を説明する用語になっています',
        };
    }
}
