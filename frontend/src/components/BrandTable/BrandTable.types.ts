import type { components } from '../../api/schema'

/** 所属のパンくずの 1 段。API の NodeRefResource をそのまま使う */
export type BrandTableNode = components['schemas']['NodeRefResource']

/**
 * 表の 1 行に出す銘柄。
 * 銘柄の一覧を返す公開の API はまだ無い(#42・#43 で足す)ので、表が要る項目をここで決め、
 * 使う画面が API の型からこの形に変えて渡す(CategoryTree の CategoryTreeNode と同じ)
 */
export type BrandTableRow = {
  id: number
  name: string
  /** 英名。空の銘柄は英名の列にリンクを出さない */
  name_eng: string
  /** 所属のノードのパンくず。先頭が画面に出ない大元で、末尾が銘柄の所属するノード(CocktailIngredientResource の path と同じ並び) */
  path: readonly BrandTableNode[]
  /** 度数(%)。無ければ null */
  strength: number | null
  /** YouTube の動画の ID。無ければ null */
  youtube: string | null
  /** 動画の列で並べ替えるときに比べる更新日時 */
  updated_at: string | null
}

/** 並べ替えに使う列(見出しを押せる列) */
export type BrandSortKey = 'name' | 'name_eng' | 'affiliation' | 'strength' | 'youtube'

/** いまの並び。どの列で、昇順か */
export type BrandSort = {
  key: BrandSortKey
  isAsc: boolean
}
