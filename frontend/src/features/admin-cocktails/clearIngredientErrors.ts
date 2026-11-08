import type { IngredientEdit } from './editIngredients'
import type { IngredientErrors, IngredientField } from './ingredientErrors.types'

/**
 * 材料の行かベースを直したときに、直したところの前のメッセージを外したものを返す(直したのに誤りが出続けないように)。
 *
 * 材料の欄全体のメッセージ(件数・重複・ベースが材料に無い)は行の組み合わせやベースについてのもので、どこを直しても
 * 当てはまらなくなりうるので、どの操作でも外す。ノードと銘柄は、片方を選ぶともう片方も変わりうるので一緒に外す。
 * 消した行のメッセージは、残しても出す先が無いので行ごと外す。
 */
export function clearIngredientErrors(errors: IngredientErrors, edit: IngredientEdit): IngredientErrors {
  const fields = editedFields(edit)
  if (fields === null || !('key' in edit) || !errors.rows.has(edit.key)) {
    return errors.section.length === 0 ? errors : { ...errors, section: [] }
  }
  const rows = new Map(errors.rows)
  if (fields === 'row') {
    rows.delete(edit.key)
  } else {
    const row = { ...rows.get(edit.key) }
    for (const field of fields) delete row[field]
    rows.set(edit.key, row)
  }
  return { section: [], rows }
}

/** 操作が直す行の欄。行ごと消すなら 'row'、行の欄を直さない操作(ベース・足す・並べ替え)なら null */
function editedFields(edit: IngredientEdit): readonly IngredientField[] | 'row' | null {
  switch (edit.kind) {
    case 'node':
    case 'brand':
      return ['category_id', 'brand_id']
    case 'amount':
      return ['amount']
    case 'optional':
      return ['is_optional']
    case 'remove':
      return 'row'
    case 'base':
    case 'add':
    case 'move':
      return null
  }
}
