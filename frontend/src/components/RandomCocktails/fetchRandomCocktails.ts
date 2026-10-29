import { api } from '../../api/client'
import type { RandomCocktailsOrigin } from './RandomCocktails.types'

type RandomOriginKind = Exclude<RandomCocktailsOrigin['kind'], 'cocktail'>

/** GET /api/cocktails/random で、起点の種類ごとに送るクエリの名前 */
const RANDOM_QUERY_KEY = {
  category: 'category_id',
  brand: 'brand_id',
  base: 'base_id',
  method: 'method_id',
  glass: 'glass_id',
} as const satisfies Record<RandomOriginKind, string>

/**
 * 起点から、ランダムなカクテルを limit 件まで取る。
 * カクテルが起点なら材料が似たカクテルの API、ほかは起点を 1 つだけ送るランダムの API を呼ぶ(どちらも同じ形の一覧を返す)
 */
export function fetchRandomCocktails(origin: RandomCocktailsOrigin, limit: number) {
  if (origin.kind === 'cocktail') {
    return api.GET('/cocktails/{cocktail}/related', { params: { path: { cocktail: origin.id }, query: { limit } } })
  }
  return api.GET('/cocktails/random', { params: { query: { limit, [RANDOM_QUERY_KEY[origin.kind]]: origin.id } } })
}
