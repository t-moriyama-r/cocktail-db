import type { CocktailSort, CocktailSortKey } from './CocktailTable.types'

/**
 * 見出しを押したときの次の並び。同じ列をもう一度押すと向きを逆にする。
 * 初めて押した列の向きは、大きいほうを先に見たい列(度数・甘さ・動画・評価)を降順、ほかを昇順にする(旧の sortBy と同じ)。
 */
export function nextCocktailSort(current: CocktailSort | null, key: CocktailSortKey): CocktailSort {
  if (current?.key === key) {
    return { key, isAsc: !current.isAsc }
  }
  return { key, isAsc: !DESC_FIRST_KEYS.has(key) }
}

const DESC_FIRST_KEYS: ReadonlySet<CocktailSortKey> = new Set(['strength', 'sweet_degree', 'youtube', 'rate'])
