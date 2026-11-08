import type { CategorySaveWarning } from './categoryApi'

/** 保存の後に、カテゴリ木の画面へ移るときに持たせる知らせ(履歴の state) */
export type CategoryTreeNotice = { notice: string }

/** 警告の項目の名前(画面の欄の名前) */
const FIELD_LABEL: Readonly<Record<string, string>> = { name: '名前', name_eng: '英名' }

/**
 * 保存できたときの知らせ。保存の後の警告(英名が同じ親の下のほかのノードと同じ)があれば、欄の名前を添えて続ける。
 * 例: 「「ジン」を保存しました。英名: 同じ名前が存在しています」
 */
export function savedNotice(name: string, warnings: readonly CategorySaveWarning[]): CategoryTreeNotice {
  return withWarnings(`「${name}」を保存しました`, warnings)
}

/**
 * 親を付け替えたときの知らせ(#125)。移した先の根から親までの名前を出す。付け替えも保存なので、保存の後の警告があれば続ける。
 * 例: 「「ドライジン」を「その他 / ミント」の下へ移しました」
 */
export function movedNotice(name: string, parentPath: readonly string[], warnings: readonly CategorySaveWarning[]): CategoryTreeNotice {
  return withWarnings(`「${name}」を「${parentPath.join(' / ')}」の下へ移しました`, warnings)
}

/** 消したときの知らせ(#125)。例: 「「ドライジン」を消しました」 */
export function deletedNotice(name: string): CategoryTreeNotice {
  return { notice: `「${name}」を消しました` }
}

function withWarnings(lead: string, warnings: readonly CategorySaveWarning[]): CategoryTreeNotice {
  const details = warnings.map((warning) => `${FIELD_LABEL[warning.field] ?? warning.field}: ${warning.message}`)
  return { notice: [lead, ...details].join('。') }
}

/**
 * 履歴の state から知らせを読む。state は前の画面が何でも入れられるので、形を確かめてから使う。
 * 形が合わなければ null(何も出さない)。
 */
export function readCategoryTreeNotice(state: unknown): string | null {
  if (typeof state !== 'object' || state === null || !('notice' in state)) {
    return null
  }
  const { notice } = state
  return typeof notice === 'string' && notice !== '' ? notice : null
}
