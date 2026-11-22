import type { components } from '../../api/schema'
import type { CocktailSort, CocktailSortKey } from '../../components/CocktailTable/CocktailTable.types'
import { nextCocktailSort } from '../../components/CocktailTable/nextCocktailSort'
import { defaultSearchFormValues, type SearchConditions, type SearchFormValues, type SearchMode } from './searchForm'
import { selectionFromIds, toSendGroups, toSendIds, type SelectionTree } from './selection'

/**
 * 条件検索の条件と、結果の画面の URL のクエリを相互に変える純関数(#102)。
 * 検索 API は POST(#163)だが、戻るボタン・共有・読み直しのため、画面の URL に条件を残す。
 *
 * クエリの形(既定値のものは書かない。例: `?n=12,45,301&mode=having&m=1,3`)
 *
 * | キー | 中身 | 書かないとき |
 * |---|---|---|
 * | `n` | 材料のノードの ID をカンマ区切り。子がすべて選ばれた親は親 1 つに畳む(selection.ts の toSendIds) | 材料なし |
 * | `mode` | `and`・`having` | OR 検索 |
 * | `easy` | `1` | かんたん検索なし |
 * | `m` | チェックの入っている技法の ID をカンマ区切り。すべて外したときは空(`m=`) | すべての技法にチェック |
 * | `sort`・`order` | 並べ替えの列と向き(API の #167 と同じ名前) | 既定の並び(ID の昇順) |
 * | `page` | ページ(2 以上) | 1 ページ目 |
 *
 * 読むときは、不正な値(数字でない ID・知らない mode・負のページなど)を無視して既定値にする。
 * 木に無いノードの ID・無い技法の ID は、フォームの値に戻すとき(formValuesFromQuery)に捨てる。
 * カンマは URLSearchParams で書くと %2C になって 3 倍の長さになるので、クエリは自前で組み立てる(値は数字と決まった英字だけ)
 */

/** 条件検索の結果の画面の URL(旧と同じ) */
export const SEARCH_RESULT_PATH = '/result/Cocktails'

/** URL のクエリから読んだ条件。ID は木・技法の一覧と突き合わせる前のもの */
export type SearchQuery = {
  readonly nodeIds: readonly number[]
  readonly mode: SearchMode
  readonly isEasy: boolean
  /** チェックの入っている技法の ID。null ならすべての技法にチェック(既定) */
  readonly methodIds: readonly number[] | null
  readonly sort: CocktailSort | null
  /** 1 から */
  readonly page: number
}

export const defaultSearchQuery: SearchQuery = {
  nodeIds: [],
  mode: 'or',
  isEasy: false,
  methodIds: null,
  sort: null,
  page: 1,
}

const MODES: readonly SearchMode[] = ['or', 'and', 'having']

/** 並べ替えの列。表の列(CocktailSortKey)と API の sort(#167)で同じ名前。API の id は既定の並びと同じなので URL では使わない */
const SORT_KEYS: readonly CocktailSortKey[] = [
  'method',
  'name',
  'name_eng',
  'base',
  'recipe_count',
  'sweet_degree',
  'rate',
  'strength',
  'youtube',
]

/** 1 以上の整数の文字だけを数にする。それ以外は null */
function parsePositiveInt(text: string): number | null {
  if (!/^\d+$/.test(text)) return null
  const value = Number(text)
  return Number.isSafeInteger(value) && value >= 1 ? value : null
}

/** カンマ区切りの ID。数字でないものは捨て、重複は 1 つにする(並びは最初に出た順) */
function parseIds(text: string): number[] {
  const ids = text.split(',').map((token) => parsePositiveInt(token.trim()))
  return [...new Set(ids.filter((id): id is number => id !== null))]
}

function parseMethodIds(text: string | null): number[] | null {
  if (text === null) return null
  // 空(m=)は「すべて外した」。値があるのに 1 つも読めなければ、不正な値として既定(すべて)にする
  if (text.trim() === '') return []
  const ids = parseIds(text)
  return ids.length === 0 ? null : ids
}

function parseSort(sort: string | null, order: string | null): CocktailSort | null {
  const key = SORT_KEYS.find((candidate) => candidate === sort)
  if (key === undefined) return null
  if (order === 'asc' || order === 'desc') return { key, isAsc: order === 'asc' }
  // 向きが無い・不正なら、見出しを初めて押したときの向き(API で order を省いたときと同じ)
  return nextCocktailSort(null, key)
}

/** URL のクエリ(`?` 付きでもよい)から条件を読む。不正な値は無視して既定値にする */
export function parseSearchQuery(search: string): SearchQuery {
  const params = new URLSearchParams(search)
  const mode = MODES.find((candidate) => candidate === params.get('mode')) ?? defaultSearchQuery.mode
  const page = parsePositiveInt(params.get('page') ?? '') ?? defaultSearchQuery.page
  return {
    nodeIds: parseIds(params.get('n') ?? ''),
    mode,
    isEasy: params.get('easy') === '1',
    methodIds: parseMethodIds(params.get('m')),
    sort: parseSort(params.get('sort'), params.get('order')),
    page,
  }
}

/** 条件をクエリの文字列(`?` を付けない)にする。既定値のものは書かない */
export function formatSearchQuery(query: SearchQuery): string {
  const parts: string[] = []
  if (query.nodeIds.length > 0) parts.push(`n=${query.nodeIds.join(',')}`)
  if (query.mode !== 'or') parts.push(`mode=${query.mode}`)
  if (query.isEasy) parts.push('easy=1')
  if (query.methodIds !== null) parts.push(`m=${query.methodIds.join(',')}`)
  if (query.sort !== null) parts.push(`sort=${query.sort.key}`, `order=${query.sort.isAsc ? 'asc' : 'desc'}`)
  if (query.page > 1) parts.push(`page=${query.page}`)
  return parts.join('&')
}

/** 結果の画面の URL。条件が既定値だけならクエリを付けない */
export function searchResultUrl(query: SearchQuery): string {
  const search = formatSearchQuery(query)
  return search === '' ? SEARCH_RESULT_PATH : `${SEARCH_RESULT_PATH}?${search}`
}

/**
 * 検索ボタンで渡された条件を、URL に残す条件にする。新しく検索するので、並べ替えとページは既定に戻す。
 * 技法がすべてチェックなら m を書かない(後から技法が増えても「すべて」のままにするため)
 */
export function searchQueryFromConditions(
  tree: SelectionTree,
  methods: readonly { readonly id: number }[],
  conditions: SearchConditions,
): SearchQuery {
  const allMethods = conditions.methodIds.length === methods.length
  return {
    ...defaultSearchQuery,
    nodeIds: toSendIds(tree, conditions.selection),
    mode: conditions.mode,
    isEasy: conditions.isEasy,
    methodIds: allMethods ? null : conditions.methodIds,
  }
}

/** URL から読んだ条件を、フォームの値に戻す。木に無いノード・無い技法の ID は捨てる */
export function formValuesFromQuery(
  tree: SelectionTree,
  methods: readonly { readonly id: number }[],
  query: SearchQuery,
): SearchFormValues {
  const checked = query.methodIds === null ? null : new Set(query.methodIds)
  return {
    ...defaultSearchFormValues,
    mode: query.mode,
    isEasy: query.isEasy,
    uncheckedMethodIds: new Set(checked === null ? [] : methods.map((method) => method.id).filter((id) => !checked.has(id))),
    selection: selectionFromIds(tree, query.nodeIds),
  }
}

export type CocktailSearchRequest = components['schemas']['CocktailSearchRequest']

/**
 * 検索 API(POST /api/cocktails/search)に送る本文。材料は、AND 検索ならまとまりの並び(category_groups。#164)、
 * ほかは ID の並び(category_ids)。技法が 0 個なら空の配列(API はすべての技法として扱う)
 */
export function searchRequestBody(
  tree: SelectionTree,
  conditions: SearchConditions,
  sort: CocktailSort | null,
  page: number,
): CocktailSearchRequest {
  const ingredients =
    conditions.mode === 'and'
      ? { category_groups: toSendGroups(tree, conditions.selection) }
      : { category_ids: toSendIds(tree, conditions.selection) }
  return {
    mode: conditions.mode,
    ...ingredients,
    method_ids: [...conditions.methodIds],
    is_easy: conditions.isEasy,
    ...(sort === null ? {} : { sort: sort.key, order: sort.isAsc ? 'asc' : 'desc' }),
    page,
  }
}
