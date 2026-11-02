import type { components, paths } from '../../api/schema'
import type { SearchMode } from './searchForm'
import { defaultSearchQuery } from './searchQuery'
import {
  browserStorage,
  hasSavedSearch,
  saveSearchQuery,
  SEARCH_STORAGE_CHANGED_EVENT,
  type SearchStorageSlot,
} from './searchStorage'

/**
 * 旧サイトがブラウザに保存した検索条件(旧 Modules/localStorageHelper.js)を、新しい保存(searchStorage.ts。#104)に移す(#175)。
 *
 * 旧は同じドメインで、キー `searchInstant`(「検索」で保存・「リロード」で読む)・`searchUser`(「保存」で保存・「読み込み」で読む)に
 * `{searchMethod, methods, categories, middles, is_easily}` の JSON を置いていた。ID は旧のカテゴリ・ミドル・技法の ID なので、
 * そのまま読むと別のノードとして戻ってしまう。そこで、画面を開いたときに旧のキーがあれば、次のように移す。
 *
 * - 旧の ID は POST /api/legacy/search-condition(#290)で新しいノード・技法の ID に直す(直し方は BE の LegacySearchCondition)。
 *   検索方法・かんたん検索はそのまま移す
 * - 直したら新しいキーに保存し、旧のキーを消す。戻せなかった ID(対応の無い ID など)の数を返し、画面に出す
 * - API が失敗した・新しいキーに保存できなかったときは、旧のキーを消さず、次に開いたときにやり直す
 * - 旧のキーの値が壊れている(JSON でない・オブジェクトでない)ときは、やり直しても直らないので旧のキーを消し、その数を返す
 * - 新しいキーにもう保存があるとき(前に API が失敗しているあいだに新しい画面で保存した)は、新しい方を残して旧のキーを消す
 * - 開いたときにフォームへ戻すことはしない(docs/decisions/search-screen.md の 4)。戻すのは今までどおり「読み込み」「リロード」
 */

/** 保存先ごとの旧のキー */
export const LEGACY_SEARCH_STORAGE_KEYS: Readonly<Record<SearchStorageSlot, string>> = {
  instant: 'searchInstant',
  user: 'searchUser',
}

const SLOTS: readonly SearchStorageSlot[] = ['instant', 'user']

const MODES: readonly SearchMode[] = ['or', 'and', 'having']

/** 旧の保存値から読んだ条件。ID は旧の ID */
export type LegacySearch = {
  readonly mode: SearchMode
  readonly isEasy: boolean
  readonly categories: readonly number[]
  readonly middles: readonly number[]
  /** チェックの入っていた技法。旧の値に技法の配列が無いときは null(すべての技法として移す) */
  readonly methods: readonly number[] | null
  /** ID として読めなかった値(数字でないもの)の数。戻せなかった数に足す */
  readonly invalidCount: number
}

/** API に送る本文と、返ってくる本文 */
export type LegacySearchConditionRequest = components['schemas']['ConvertLegacySearchConditionRequest']
export type LegacySearchConditionResponse =
  paths['/legacy/search-condition']['post']['responses'][200]['content']['application/json']

/** 旧の ID を新しい ID に直す関数。失敗したら null */
export type ConvertLegacySearch = (body: LegacySearchConditionRequest) => Promise<LegacySearchConditionResponse | null>

/** 移した結果。どちらも 0 なら、画面には何も出さない */
export type LegacySearchMigrationResult = {
  /** 戻せなかった ID の数(保存先 2 つの合計) */
  readonly droppedCount: number
  /** 壊れていて消した旧の保存値の数 */
  readonly brokenCount: number
}

/** 1 以上の整数か、その数字だけの文字列なら数にする。それ以外は null */
function toLegacyId(value: unknown): number | null {
  if (typeof value === 'number') return Number.isSafeInteger(value) && value >= 1 ? value : null
  if (typeof value === 'string' && /^\d+$/.test(value)) {
    const id = Number(value)
    return Number.isSafeInteger(id) && id >= 1 ? id : null
  }
  return null
}

/** 旧の ID の配列を読む。配列でなければ null。読めない値は数えて捨てる */
function readIds(value: unknown): { ids: number[]; invalid: number } | null {
  if (!Array.isArray(value)) return null
  const ids: number[] = []
  let invalid = 0
  for (const item of value) {
    const id = toLegacyId(item)
    if (id === null) invalid += 1
    else ids.push(id)
  }
  return { ids, invalid }
}

/** 旧の保存値(JSON の文字列)を読む。JSON でない・オブジェクトでないときは null(壊れている) */
export function parseLegacySearch(text: string): LegacySearch | null {
  let parsed: unknown
  try {
    parsed = JSON.parse(text)
  } catch {
    return null
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return null
  const record = parsed as Record<string, unknown>

  const categories = readIds(record.categories)
  const middles = readIds(record.middles)
  const methods = readIds(record.methods)
  return {
    mode: MODES.find((mode) => mode === record.searchMethod) ?? defaultSearchQuery.mode,
    isEasy: record.is_easily === true,
    categories: categories?.ids ?? [],
    middles: middles?.ids ?? [],
    methods: methods?.ids ?? null,
    invalidCount: (categories?.invalid ?? 0) + (middles?.invalid ?? 0) + (methods?.invalid ?? 0),
  }
}

/** 旧のキーの値。無い・読めないときは null */
function readLegacy(slot: SearchStorageSlot): string | null {
  try {
    return browserStorage()?.getItem(LEGACY_SEARCH_STORAGE_KEYS[slot]) ?? null
  } catch {
    return null
  }
}

function removeLegacy(slot: SearchStorageSlot): void {
  try {
    browserStorage()?.removeItem(LEGACY_SEARCH_STORAGE_KEYS[slot])
  } catch {
    // 消せなければ、次に開いたときにもう一度移す(新しいキーに保存があれば、そちらを残して消し直す)
  }
}

type SlotResult = { dropped: number; broken: number; saved: boolean }

const NOTHING: SlotResult = { dropped: 0, broken: 0, saved: false }

async function migrateSlot(slot: SearchStorageSlot, convert: ConvertLegacySearch): Promise<SlotResult> {
  const text = readLegacy(slot)
  if (text === null) return NOTHING

  const legacy = parseLegacySearch(text)
  if (legacy === null) {
    removeLegacy(slot)
    return { ...NOTHING, broken: 1 }
  }
  if (hasSavedSearch(slot)) {
    removeLegacy(slot)
    return NOTHING
  }

  const converted = await convert({
    categories: [...legacy.categories],
    middles: [...legacy.middles],
    ...(legacy.methods === null ? {} : { methods: [...legacy.methods] }),
  })
  if (converted === null) return NOTHING

  const saved = saveSearchQuery(slot, {
    ...defaultSearchQuery,
    nodeIds: converted.category_ids,
    mode: legacy.mode,
    isEasy: legacy.isEasy,
    methodIds: legacy.methods === null ? null : converted.method_ids,
  })
  if (!saved) return NOTHING

  removeLegacy(slot)
  return { dropped: converted.dropped_count + legacy.invalidCount, broken: 0, saved: true }
}

/**
 * 旧のキーの保存値を、新しいキーに移す。新しいキーに保存したら、フォームが読み直せるよう SEARCH_STORAGE_CHANGED_EVENT を送る。
 * 例外は投げない(localStorage・API が使えなければ何もしない)
 */
export async function migrateLegacySearches(convert: ConvertLegacySearch): Promise<LegacySearchMigrationResult> {
  const results: SlotResult[] = []
  // 保存先ごとに順に移す(API を同時に 2 回呼ばない)
  for (const slot of SLOTS) {
    try {
      results.push(await migrateSlot(slot, convert))
    } catch {
      results.push(NOTHING)
    }
  }
  if (results.some((result) => result.saved) && typeof window !== 'undefined') {
    window.dispatchEvent(new Event(SEARCH_STORAGE_CHANGED_EVENT))
  }
  return {
    droppedCount: results.reduce((sum, result) => sum + result.dropped, 0),
    brokenCount: results.reduce((sum, result) => sum + result.broken, 0),
  }
}

/** 画面に出す文。出すものが無ければ空の配列 */
export function legacySearchMessages({ droppedCount, brokenCount }: LegacySearchMigrationResult): string[] {
  const messages: string[] = []
  if (droppedCount > 0) {
    messages.push(
      `旧サイトで保存した検索条件(「読み込み」「リロード」で戻す条件)を、今のサイトの材料に直しました。戻せなかった材料・製法 ${droppedCount} 件は、条件から外しています。`,
    )
  }
  if (brokenCount > 0) {
    messages.push('旧サイトで保存した検索条件が壊れていて読めなかったため、消しました。')
  }
  return messages
}
