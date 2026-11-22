/**
 * ヘッダーの名前検索の入力を、送れる語・禁止文字を含む・空、のどれかに分ける(#99)。
 *
 * 禁止文字は旧の画面(旧 Forms/Search/FromName/fromName.vue)と同じ。旧はこれを含むと、検索せずにボタンを赤い「Error」にした。
 */
const FORBIDDEN_CHARACTERS = /[!！？?;；<>_＿＜＞\\|/"'*{}^[\]]/

export type NameSearchInput =
  /** 空・空白(半角・全角)だけ。送らない */
  | { kind: 'empty' }
  /** 禁止文字を含む。送らない */
  | { kind: 'forbidden' }
  /** 送る語(前後の空白を除いたもの) */
  | { kind: 'word'; word: string }

export function parseNameSearchInput(input: string): NameSearchInput {
  // trim は全角の空白(U+3000)も除く
  const word = input.trim()
  if (word === '') {
    return { kind: 'empty' }
  }
  if (FORBIDDEN_CHARACTERS.test(word)) {
    return { kind: 'forbidden' }
  }
  return { kind: 'word', word }
}

/** 名前検索の結果の画面の URL(旧と同じ /result/FromNameResult/:word) */
export const nameSearchResultPath = (word: string) => `/result/FromNameResult/${encodeURIComponent(word)}`
