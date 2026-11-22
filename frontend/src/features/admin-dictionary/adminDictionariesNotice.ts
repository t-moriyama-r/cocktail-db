/** 保存・削除の後に、用語辞典の一覧へ移るときに持たせる知らせ(履歴の state) */
export type AdminDictionariesNotice = { notice: string }

/**
 * 履歴の state から知らせを読む。state は前の画面が何でも入れられるので、形を確かめてから使う。
 * 形が合わなければ null(何も出さない)。
 */
export function readAdminDictionariesNotice(state: unknown): string | null {
  if (typeof state !== 'object' || state === null || !('notice' in state)) {
    return null
  }
  const { notice } = state
  return typeof notice === 'string' && notice !== '' ? notice : null
}
