import type { CocktailIngredientRow } from './cocktailFormValues'
import type { IngredientErrors, IngredientField } from './ingredientErrors.types'

/**
 * 材料の行かベースを直した後に、直したところの前のメッセージを外したものを返す(直したのに誤りが出続けないように)。
 *
 * 外すのは、操作の前後で値の変わった欄のメッセージと、消えた行のメッセージ。1 つの操作が別の欄も変えること
 * (銘柄を選ぶとノードも変わる・ベースを選ぶと空の 1 行目にも入る)があるので、操作の種類ではなく値の差で決める。
 * 材料の欄全体のメッセージ(件数・重複・ベースが材料に無いなど)は行の組み合わせやベースについてのもので、
 * どこを直しても当てはまらなくなりうるので、どの操作でも外す。
 */
export function clearIngredientErrors(
  errors: IngredientErrors,
  before: readonly CocktailIngredientRow[],
  after: readonly CocktailIngredientRow[],
): IngredientErrors {
  const afterRows = new Map(after.map((row) => [row.key, row]))
  const rows = new Map(errors.rows)
  for (const row of before) {
    const next = afterRows.get(row.key)
    const current = rows.get(row.key)
    if (current === undefined) continue
    if (next === undefined) {
      rows.delete(row.key)
      continue
    }
    const kept = { ...current }
    for (const field of changedFields(row, next)) delete kept[field]
    rows.set(row.key, kept)
  }
  return { section: [], rows }
}

/** 行の値の変わった欄(API の項目名) */
function changedFields(before: CocktailIngredientRow, after: CocktailIngredientRow): IngredientField[] {
  return (Object.keys(ROW_VALUE) as IngredientField[]).filter((field) => before[ROW_VALUE[field]] !== after[ROW_VALUE[field]])
}

/** API の項目名 → 行の入力の値の名前(Record なので、API の項目が増減すれば型で落ちる) */
const ROW_VALUE: Readonly<Record<IngredientField, Exclude<keyof CocktailIngredientRow, 'key'>>> = {
  category_id: 'categoryId',
  brand_id: 'brandId',
  amount: 'amount',
  is_optional: 'isOptional',
}
