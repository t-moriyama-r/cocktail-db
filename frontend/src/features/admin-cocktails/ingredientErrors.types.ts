import type { components } from '../../api/schema'

/** 材料の行の欄(API の項目名。保存の API の ingredients の 1 行の項目) */
export type IngredientField = keyof components['schemas']['SaveCocktailRequest']['ingredients'][number]

/** 材料の 1 行の、欄ごとの検証のメッセージ */
export type IngredientRowErrors = Partial<Record<IngredientField, readonly string[]>>

/** 材料の欄に出すメッセージ。section は欄全体(件数・送る前の確かめなど、行に付かないもの)、rows は行の key ごと */
export type IngredientErrors = {
  section: readonly string[]
  rows: ReadonlyMap<number, IngredientRowErrors>
}
