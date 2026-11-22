import type { CocktailAliasWarning } from './cocktailApi'

/**
 * 保存の後に、カクテルの一覧へ移るときに持たせる知らせ(履歴の state。#83)。
 * 別名がほかのカクテルの名前・別名と同じだったとき(保存は済んでいる)だけ持たせ、一覧の上に出す
 */
export type CocktailSaveNotice = { cocktailSaveNotice: { name: string; warnings: readonly CocktailAliasWarning[] } }

/** 保存した名前と警告から、一覧へ持たせる知らせを作る。警告が無ければ null(何も持たせない) */
export function cocktailSaveNotice(name: string, warnings: readonly CocktailAliasWarning[]): CocktailSaveNotice | null {
  return warnings.length === 0 ? null : { cocktailSaveNotice: { name, warnings } }
}

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null

const isWarning = (value: unknown): value is CocktailAliasWarning =>
  isRecord(value) &&
  typeof value.alias === 'string' &&
  typeof value.message === 'string' &&
  typeof value.cocktailId === 'number' &&
  Number.isInteger(value.cocktailId) &&
  typeof value.cocktailName === 'string'

/**
 * 履歴の state から知らせを読む。state は前の画面が何でも入れられるので、形を確かめてから使う。
 * 形が合わない・警告が無ければ null(何も出さない)
 */
export function readCocktailSaveNotice(state: unknown): CocktailSaveNotice['cocktailSaveNotice'] | null {
  if (!isRecord(state) || !isRecord(state.cocktailSaveNotice)) {
    return null
  }
  const { name, warnings } = state.cocktailSaveNotice
  if (typeof name !== 'string' || !Array.isArray(warnings) || warnings.length === 0 || !warnings.every(isWarning)) {
    return null
  }
  return { name, warnings }
}
