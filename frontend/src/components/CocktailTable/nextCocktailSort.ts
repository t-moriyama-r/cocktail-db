import type { CocktailSort, CocktailSortKey } from './CocktailTable.types'

/**
 * 見出しを押したときの次の並び。同じ列をもう一度押すと向きを逆にし、別の列ならその列の初めの向きから始める(旧の sortBy と同じ)。
 */
export function nextCocktailSort(current: CocktailSort | null, key: CocktailSortKey): CocktailSort {
  if (current?.key === key) {
    return { key, isAsc: !current.isAsc }
  }
  return { key, isAsc: FIRST_DIRECTION[key] === 'asc' }
}

/**
 * 列ごとの、初めて押したときの向き。大きいほうを先に見たい列(数の大きさ・新しさに意味がある列)を降順にする。
 * 列を足したときに向きを決め忘れないよう、全部の列を書く
 */
const FIRST_DIRECTION: Record<CocktailSortKey, 'asc' | 'desc'> = {
  method: 'asc',
  name: 'asc',
  name_eng: 'asc',
  base: 'asc',
  recipe_count: 'asc',
  sweet_degree: 'desc',
  rate: 'desc',
  strength: 'desc',
  youtube: 'desc',
}
