/**
 * 名前を比べるために、全角の英数字を半角に、カタカナ(ァ〜ヶ)をひらがなにそろえる。
 * 旧の Helper.katakanaToHiragana(本番のビルドと同じ)の写し。大文字と小文字・半角のカタカナ・長音記号はそろえない
 */
export function toComparableKana(text: string): string {
  return text
    .replace(/[Ａ-Ｚａ-ｚ０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0))
    .replace(/[ァ-ヶ]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0x60))
}
