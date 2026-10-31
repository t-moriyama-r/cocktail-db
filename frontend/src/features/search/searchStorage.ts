import { toSearchConditions, type SearchFormValues } from './searchForm'
import { formatSearchQuery, formValuesFromQuery, parseSearchQuery, searchQueryFromConditions } from './searchQuery'
import type { SelectionTree } from './selection'

/**
 * 条件検索の条件をブラウザ(localStorage)に保存して読む純関数(#104。旧 Modules/localStorageHelper.js)。
 *
 * 旧と同じく 2 つの保存先を持つ。
 *
 * | 保存先 | 書くとき | 読むとき | 旧のキー |
 * |---|---|---|---|
 * | `instant` | 「検索」を押したとき(検索の成否によらない) | 「リロード」を押したとき | `searchInstant` |
 * | `user` | 「保存」を押したとき | 「読み込み」を押したとき | `searchUser` |
 *
 * - 画面を開いたときには読まない(docs/decisions/search-screen.md の 4。旧も開くたびに既定の状態だった)
 * - キーには版(`v1`)を入れ、旧のキー(`searchInstant`・`searchUser`。旧の ID が入っている)とは分ける。旧のキーの値を直すのは #175
 * - 中身は URL のクエリと同じ形の文字列(searchQuery.ts。例: `n=12,45&mode=and&m=1,3`)。読むときも URL と同じく、
 *   不正な値は既定値にし、木に無いノード・無い技法の ID は捨てる(selection.ts の selectionFromIds。#304)。
 *   技法がすべてチェックなら書かないので、後から技法が増えても「すべて」のまま戻る
 * - localStorage が使えない(無い・プライベートブラウズで例外を投げる・容量を超えた)ときは、保存せず、
 *   保存が無いものとして扱う(旧は読んだ値を検証せず、壊れた値で画面が壊れた。新は例外を握る)
 */

/** 保存先の種類 */
export type SearchStorageSlot = 'instant' | 'user'

/** 保存先ごとの localStorage のキー。旧のキー(searchInstant・searchUser)とは別にする */
export const SEARCH_STORAGE_KEYS: Readonly<Record<SearchStorageSlot, string>> = {
  instant: 'cocktail-db.search.instant.v1',
  user: 'cocktail-db.search.user.v1',
}

/** 使える localStorage。無い・触ると例外を投げるときは null */
function storage(): Storage | null {
  try {
    return typeof window === 'undefined' ? null : window.localStorage
  } catch {
    return null
  }
}

/** 保存した文字列。保存が無い・読めないときは null */
function readRaw(slot: SearchStorageSlot): string | null {
  try {
    return storage()?.getItem(SEARCH_STORAGE_KEYS[slot]) ?? null
  } catch {
    return null
  }
}

/** その保存先に、読める保存があるか(「読み込み」「リロード」を押せるか) */
export function hasSavedSearch(slot: SearchStorageSlot): boolean {
  return readRaw(slot) !== null
}

/**
 * フォームの値を保存する。保存できたら true、localStorage が使えず保存できなかったら false。
 * 材料は送る ID と同じく、子がすべて選ばれた親は親 1 つに畳む(toSendIds)
 */
export function saveSearch(
  slot: SearchStorageSlot,
  tree: SelectionTree,
  methods: readonly { readonly id: number }[],
  values: SearchFormValues,
): boolean {
  const text = formatSearchQuery(searchQueryFromConditions(tree, methods, toSearchConditions(methods, values)))
  try {
    const target = storage()
    if (target === null) return false
    target.setItem(SEARCH_STORAGE_KEYS[slot], text)
    return true
  } catch {
    return false
  }
}

/** 保存した条件を、フォームの値に戻す。保存が無い・読めないときは null */
export function loadSearch(
  slot: SearchStorageSlot,
  tree: SelectionTree,
  methods: readonly { readonly id: number }[],
): SearchFormValues | null {
  const text = readRaw(slot)
  if (text === null) return null
  return formValuesFromQuery(tree, methods, parseSearchQuery(text))
}
