import type { components } from '../../api/schema'

type CategoryRole = components['schemas']['CategoryRole']

/**
 * 根の役割の出し方(docs/decisions/category-root-role.md)。役割の無い根(null)は旧の種別 1〜3(蒸留酒・醸造酒・混成酒)。
 * カテゴリ木の画面(#123)の注記と、根の編集フォームの役割の欄(#310)で使う。
 */
export const ROLE_LABEL: Record<CategoryRole | 'none', string> = {
  none: 'なし',
  soft_drink: 'ソフトドリンク',
  other: 'その他',
}

/** 根の編集フォームで選ばせる役割の並び(なし・ソフトドリンク・その他) */
export const ROLE_OPTIONS: readonly (CategoryRole | null)[] = [null, 'soft_drink', 'other']
