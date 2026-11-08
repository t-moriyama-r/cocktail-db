/** 材料の行の欄(API の項目名) */
export type IngredientField = 'category_id' | 'brand_id' | 'amount' | 'is_optional'

/** 材料の 1 行の、欄ごとの検証のメッセージ */
export type IngredientRowErrors = Partial<Record<IngredientField, readonly string[]>>

/** 材料の欄に出すメッセージ。section は欄全体(件数・送る前の確かめなど)、rows は行の key ごと */
export type IngredientErrors = {
  section: readonly string[]
  rows: ReadonlyMap<number, IngredientRowErrors>
}

export const NO_INGREDIENT_ERRORS: IngredientErrors = { section: [], rows: new Map() }
