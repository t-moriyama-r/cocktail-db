/**
 * 詳細の画面(ノード・カクテル・銘柄・用語)の URL(App.tsx のルートと同じ URL)。
 * ルートの URL を変えるときに直し漏れが出ないよう、詳細の画面へのリンク先はここで作る(#545)
 */

/** ノード(カテゴリ・ミドル・材料)の詳細 */
export function categoryPath(id: number): string {
  return `/category/${id}`
}

/** カクテルの詳細 */
export function cocktailPath(id: number): string {
  return `/detail/cocktail/${id}`
}

/** 銘柄の詳細 */
export function brandPath(id: number): string {
  return `/detail/brand/${id}`
}

/** 用語の詳細 */
export function dictionaryPath(id: number): string {
  return `/dictionary/${id}`
}
