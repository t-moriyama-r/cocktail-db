/**
 * 並べ替えで比べるための文字列にそろえる(旧 Modules/helper.js の katakanaToHiragana と同じ変換)。
 * カクテルの名前はカタカナ・ひらがな、全角・半角の英数字が混ざって登録されていて、そのまま比べると同じ読みの名前が離れて並ぶ。
 * そのため全角の英数字を半角に、カタカナをひらがなにそろえる。大文字と小文字はそろえない(旧と同じ)。
 */
export function toSortableKana(text: string): string {
  return text
    .replace(/[Ａ-Ｚａ-ｚ０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - FULL_WIDTH_OFFSET))
    .replace(/[ァ-ヶ]/g, (c) => String.fromCharCode(c.charCodeAt(0) - KATAKANA_TO_HIRAGANA_OFFSET))
}

/** 全角の英数字と半角の英数字の文字コードの差 */
const FULL_WIDTH_OFFSET = 0xfee0

/** カタカナ(ァ〜ヶ)とひらがなの文字コードの差 */
const KATAKANA_TO_HIRAGANA_OFFSET = 0x60
