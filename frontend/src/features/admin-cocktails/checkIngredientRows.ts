import type { CocktailIngredientRow } from './cocktailFormValues'

/**
 * 材料の行を保存の前に確かめ、保存を止める理由の文(旧 CocktailEdit.vue の send と同じ文言)を返す。止めなくてよければ空。
 * nodePaths は、ノードの ID から根からの道のり(根が先頭で、そのノードが末尾)を引く表。
 *
 * 同じ材料を 2 行に入れる・ベースを材料に入れ忘れる、は入れ間違いだが、API はどちらも確かめない(API が見るのは件数と、
 * 行ごとのノード・銘柄・量)。旧は送る前に画面で止めていたので、新も送る前に止める(#128)。
 * 旧はベースと材料を 2 段目のカテゴリどうしで比べていた。新はどの深さのノードも選べ、ベースを「ジン」、材料を
 * 「ドライジン」の銘柄にすることがあるので、材料のノードがベースそのものか配下なら、ベースが材料にあるとみなす。
 *
 * ノードを選んでいない行は数えない(旧も、カテゴリの無い行を飛ばした)。ベースが空のときと、ノードのある行が 1 つも無い
 * ときにベースを確かめないのは、旧ではそこでベース・1・2 行目の必須の確かめが先に止めていたため。新はその必須を API の
 * 422 で欄ごとに出すので、ここで「ベースが見つかりません！」を先に出すと、必須の入れ忘れが見えなくなる。
 */
export function checkIngredientRows(
  rows: readonly CocktailIngredientRow[],
  baseCategoryId: number | null,
  nodePaths: ReadonlyMap<number, readonly { id: number }[]>,
): string[] {
  const chosen = rows.filter((row): row is CocktailIngredientRow & { categoryId: number } => row.categoryId !== null)
  const messages: string[] = []
  if (hasDuplicate(chosen)) {
    messages.push(DUPLICATE_MESSAGE)
  }
  if (baseCategoryId !== null && chosen.length > 0) {
    const containsBase = chosen.some((row) => nodePaths.get(row.categoryId)?.some((node) => node.id === baseCategoryId) ?? false)
    if (!containsBase) {
      messages.push(BASE_NOT_FOUND_MESSAGE)
    }
  }
  return messages
}

const DUPLICATE_MESSAGE = 'レシピが重複しています！'
const BASE_NOT_FOUND_MESSAGE = 'ベースが見つかりません！'

function hasDuplicate(rows: readonly { categoryId: number; brandId: number | null }[]): boolean {
  const seen = new Set<string>()
  for (const row of rows) {
    const id = `${row.categoryId}:${row.brandId ?? ''}`
    if (seen.has(id)) return true
    seen.add(id)
  }
  return false
}
