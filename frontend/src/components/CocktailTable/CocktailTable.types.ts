import type { components } from '../../api/schema'

/** 表の 1 行に出すカクテル。API の CocktailSummaryResource をそのまま使う */
export type CocktailTableRow = components['schemas']['CocktailSummaryResource']

/** 並べ替えに使う列(見出しを押せる列) */
export type CocktailSortKey =
  | 'method'
  | 'name'
  | 'name_eng'
  | 'base'
  | 'recipe_count'
  | 'sweet_degree'
  | 'rate'
  | 'strength'
  | 'youtube'

/** いまの並び。どの列で、昇順か */
export type CocktailSort = {
  key: CocktailSortKey
  isAsc: boolean
}

/**
 * 表の中で並べ替える。最初は渡した順のまま出し、見出しを押すと表の中で並べ替える
 */
export type CocktailTableLocalSort = {
  mode: 'local'
}

/**
 * 並びを外から渡す。表は渡した行の順のまま出し、見出しに sort の印を付ける。
 * 見出しを押すと、次の並び(同じ列なら逆、ほかの列なら列ごとの初めの向き)を onSortChange で返す
 */
export type CocktailTableControlledSort = {
  mode: 'controlled'
  sort: CocktailSort | null
  onSortChange: (next: CocktailSort) => void
}

export type CocktailTableSortMode = CocktailTableLocalSort | CocktailTableControlledSort
