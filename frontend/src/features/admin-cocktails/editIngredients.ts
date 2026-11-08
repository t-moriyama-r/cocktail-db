import { emptyIngredientRow, MAX_INGREDIENT_ROWS, type CocktailFormValues, type CocktailIngredientRow } from './cocktailFormValues'

/** 材料の行(#128)とベースへの操作。行は key で指す(並べ替え・削除で位置が変わっても同じ行を指すため) */
export type IngredientEdit =
  /** ベースを木で選んだ */
  | { kind: 'base'; categoryId: number }
  /** 行のノードを木で選んだ */
  | { kind: 'node'; key: number; categoryId: number }
  /** 行の銘柄を選んだ。空の選択肢を選んだら null。categoryId は銘柄の所属ノード */
  | { kind: 'brand'; key: number; brand: { id: number; categoryId: number } | null }
  | { kind: 'amount'; key: number; amount: string }
  | { kind: 'optional'; key: number; isOptional: boolean }
  /** 行を末尾に足す */
  | { kind: 'add' }
  | { kind: 'remove'; key: number }
  /** 行を 1 つ上(-1)か下(1)の行と入れ替える */
  | { kind: 'move'; key: number; offset: -1 | 1 }

/**
 * フォームの入力に、材料の行とベースへの操作を当てた入力を返す。
 *
 * ベースと 1 行目の材料は、旧の画面(CocktailEdit.vue・RecipeSelect.vue)と同じく、片方が空のうちにもう片方を選ぶと、
 * 空の側にも同じノードを入れる。ふつうベースは材料の 1 行目に入れるもので、同じノードを 2 度選ばずに済むため。
 * 入れるのは空の側にだけで、もう選んである側は変えない(選び直しで、もう片方の選んだものを消さないため)。
 *
 * 行のノードを木で選び直すと、銘柄を空にする(前のノードの銘柄が、新しいノードの配下にあるとは限らないため)。
 * 銘柄を選ぶと、ノードは銘柄の所属ノードにする(API は銘柄のある行のノードを所属ノードに限る。#19)。
 */
export function editIngredients(values: CocktailFormValues, edit: IngredientEdit): CocktailFormValues {
  switch (edit.kind) {
    case 'base':
      return selectBase(values, edit.categoryId)
    case 'node':
      return selectRowNode(values, edit.key, edit.categoryId, null)
    case 'brand':
      return edit.brand === null
        ? updateRow(values, edit.key, { brandId: null })
        : selectRowNode(values, edit.key, edit.brand.categoryId, edit.brand.id)
    case 'amount':
      return updateRow(values, edit.key, { amount: edit.amount })
    case 'optional':
      return updateRow(values, edit.key, { isOptional: edit.isOptional })
    case 'add':
      return values.ingredients.length >= MAX_INGREDIENT_ROWS
        ? values
        : { ...values, ingredients: [...values.ingredients, emptyIngredientRow(nextKey(values.ingredients))] }
    case 'remove':
      return { ...values, ingredients: values.ingredients.filter((row) => row.key !== edit.key) }
    case 'move':
      return { ...values, ingredients: moveRow(values.ingredients, edit.key, edit.offset) }
  }
}

function selectBase(values: CocktailFormValues, categoryId: number): CocktailFormValues {
  const [first, ...rest] = values.ingredients
  const fillsFirstRow = values.baseCategoryId === null && first !== undefined && first.categoryId === null
  return {
    ...values,
    baseCategoryId: categoryId,
    ingredients: fillsFirstRow ? [{ ...first, categoryId, brandId: null }, ...rest] : values.ingredients,
  }
}

function selectRowNode(values: CocktailFormValues, key: number, categoryId: number, brandId: number | null): CocktailFormValues {
  const index = values.ingredients.findIndex((row) => row.key === key)
  if (index === -1) return values
  const fillsBase = index === 0 && values.ingredients[index].categoryId === null && values.baseCategoryId === null
  return {
    ...updateRow(values, key, { categoryId, brandId }),
    baseCategoryId: fillsBase ? categoryId : values.baseCategoryId,
  }
}

function updateRow(values: CocktailFormValues, key: number, change: Partial<Omit<CocktailIngredientRow, 'key'>>): CocktailFormValues {
  return { ...values, ingredients: values.ingredients.map((row) => (row.key === key ? { ...row, ...change } : row)) }
}

/** 足す行の key。今ある行のどれとも重ならない数 */
function nextKey(rows: readonly CocktailIngredientRow[]): number {
  return rows.reduce((max, row) => Math.max(max, row.key), -1) + 1
}

function moveRow(rows: readonly CocktailIngredientRow[], key: number, offset: -1 | 1): readonly CocktailIngredientRow[] {
  const from = rows.findIndex((row) => row.key === key)
  const to = from + offset
  if (from === -1 || to < 0 || to >= rows.length) return rows
  const moved = [...rows]
  ;[moved[from], moved[to]] = [moved[to], moved[from]]
  return moved
}
