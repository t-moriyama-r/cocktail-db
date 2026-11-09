import type { CocktailFieldErrors } from './cocktailApi'
import type { IngredientErrors, IngredientField, IngredientRowErrors } from './ingredientErrors.types'

/**
 * 422 の項目ごとのメッセージを、材料の行のもの(`ingredients.<行>.<欄>`)と、それ以外に分ける。
 *
 * API の行の番号は送った並びの位置なので、送ったときの行の key(sentKeys)で行に結び付け直す。並べ替え・削除の後も、
 * メッセージがそれを出させた行に付いたまま動くように。結び付けられない材料のメッセージ(件数・度数を計算できないなど)は
 * 材料の欄全体に出す。
 */
export function splitIngredientErrors(
  errors: CocktailFieldErrors,
  sentKeys: readonly number[],
): { fieldErrors: CocktailFieldErrors; ingredientErrors: IngredientErrors } {
  const fieldErrors: Record<string, readonly string[]> = {}
  const section: string[] = []
  const rows = new Map<number, IngredientRowErrors>()
  for (const [name, messages] of Object.entries(errors)) {
    if (name !== 'ingredients' && !name.startsWith('ingredients.')) {
      fieldErrors[name] = messages
      continue
    }
    const match = ROW_FIELD.exec(name)
    const key = match === null ? undefined : sentKeys[Number(match[1])]
    const field = match?.[2]
    if (key === undefined || field === undefined || !isIngredientField(field)) {
      section.push(...messages)
      continue
    }
    rows.set(key, { ...rows.get(key), [field]: messages })
  }
  return { fieldErrors, ingredientErrors: { section, rows } }
}

const ROW_FIELD = /^ingredients\.(\d+)\.([a-z_]+)$/

/** 材料の行の欄の一覧(Record なので、API の項目が増減すれば型で落ちる) */
const INGREDIENT_FIELDS: Readonly<Record<IngredientField, true>> = {
  category_id: true,
  brand_id: true,
  amount: true,
  is_optional: true,
}

const isIngredientField = (name: string): name is IngredientField => Object.hasOwn(INGREDIENT_FIELDS, name)
