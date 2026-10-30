import { emptySelection, type TreeSelection } from './selection'

/**
 * 条件検索のフォーム(#307)の値と、検証・送る条件を作る純関数。
 * 画面の部品は CocktailSearchForm.tsx。条件を URL に入れて送るのは #102。
 */

/** 検索方法(API の mode。#163)。旧と同じく OR 検索・AND 検索・あるもの検索 */
export type SearchMode = 'or' | 'and' | 'having'

/** フォームの値 */
export type SearchFormValues = {
  readonly mode: SearchMode
  /** かんたん検索(API の is_easy) */
  readonly isEasy: boolean
  /**
   * チェックを外した技法の ID。既定は「すべての技法にチェック」なので、外したものだけを持つ。
   * こうすると、技法の一覧(GET /api/search-form)を読む前から既定値が決まり、リセットしても ID が重なって入らない
   * (旧の init は技法の配列を空にせずに入れ直していたので、リセットのたびに重なった。#307 で写さないと決めた)
   */
  readonly uncheckedMethodIds: ReadonlySet<number>
  /** 材料の選択(選ばれた葉の ID の集合。selection.ts) */
  readonly selection: TreeSelection
}

/** 既定値(旧の init と同じ: OR 検索・かんたん検索なし・技法はすべて・材料なし)。「リセット」もこれに戻す */
export const defaultSearchFormValues: SearchFormValues = {
  mode: 'or',
  isEasy: false,
  uncheckedMethodIds: new Set(),
  selection: emptySelection,
}

/** 材料が 0 個のときの文言(旧は「カテゴリは必須です。」。新は「材料」と呼ぶ。#307) */
export const INGREDIENT_REQUIRED_MESSAGE = '材料は必須です。'

/**
 * 検証の誤りの文言。誤りが無ければ null。
 * 材料は 1 個以上が必須(旧と同じ)。技法は 0 個でもよい(0 個はすべての技法として送る。docs/decisions/search-screen.md の 6)
 */
export function ingredientError(values: SearchFormValues): string | null {
  return values.selection.size === 0 ? INGREDIENT_REQUIRED_MESSAGE : null
}

/** チェックの入っている技法の ID(技法の一覧の順) */
export function checkedMethodIds(methods: readonly { readonly id: number }[], values: SearchFormValues): number[] {
  return methods.map((method) => method.id).filter((id) => !values.uncheckedMethodIds.has(id))
}

/** 技法のチェックを入れた(checked = true)・外した後の値 */
export function toggleMethod(values: SearchFormValues, id: number, checked: boolean): SearchFormValues {
  const unchecked = new Set(values.uncheckedMethodIds)
  if (checked) unchecked.delete(id)
  else unchecked.add(id)
  return { ...values, uncheckedMethodIds: unchecked }
}

/** 検索ボタンで渡す条件。API の形(category_ids・category_groups など)に直して送るのは #102 */
export type SearchConditions = {
  readonly mode: SearchMode
  readonly isEasy: boolean
  /** チェックの入っている技法の ID。0 個なら、すべての技法として送る(docs/decisions/search-screen.md の 6) */
  readonly methodIds: readonly number[]
  readonly selection: TreeSelection
}

/** フォームの値から、検索ボタンで渡す条件を作る */
export function toSearchConditions(methods: readonly { readonly id: number }[], values: SearchFormValues): SearchConditions {
  return {
    mode: values.mode,
    isEasy: values.isEasy,
    methodIds: checkedMethodIds(methods, values),
    selection: values.selection,
  }
}
