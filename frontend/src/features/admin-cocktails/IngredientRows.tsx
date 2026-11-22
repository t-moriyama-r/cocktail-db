import { useId, useState } from 'react'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance, CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'
import { FieldErrors, TextField } from '../../components/AdminForm/AdminField'
import IngredientBrandSelect from './IngredientBrandSelect'
import { MAX_INGREDIENT_ROWS, type CocktailIngredientRow } from './cocktailFormValues'
import { canAddIngredientRow, type IngredientEdit } from './editIngredients'
import type { IngredientErrors, IngredientRowErrors } from './ingredientErrors.types'
import formStyles from './CocktailForm.module.css'
import styles from './IngredientRows.module.css'

type Props = {
  rows: readonly CocktailIngredientRow[]
  /** ノードを選ぶ木 */
  nodes: CategoryTreeNode[]
  /** 木のノードごとの見え方 */
  appearance: CategoryTreeAppearance
  /** 選べるノードかを返す */
  isSelectable: (node: CategoryTreeNode) => boolean
  /** 選んでいるノードとして出す文字。出せなければ null */
  pathOf: (categoryId: number) => string | null
  errors: IngredientErrors
  onEdit: (edit: IngredientEdit) => void
}

/**
 * 材料の行の編集(旧 `component/RecipeList.vue`・`RecipeSelect.vue`。#128)。
 *
 * 旧の行は 12 行の枠で固定だったが、新は行を足す・消す・並べ替えるができる(docs/decisions/admin-screens.md の決まったこと 5)。
 * 1 行は ノード・銘柄・量・任意 で、ノードは木の部品(#297)の単一選択で根の下のどの深さからも選ぶ。行ごとに木を開いたままにすると、
 * 20 行で木が 20 個並んで画面が埋まるので、木は「ノードを選ぶ」で開いた 1 行にだけ出し、選んだら閉じる。
 */
export default function IngredientRows({ rows, nodes, appearance, isSelectable, pathOf, errors, onEdit }: Props) {
  const headingId = useId()
  // 木を開いている行の key。開くのは 1 行だけ
  const [pickingKey, setPickingKey] = useState<number | null>(null)

  return (
    <section className={styles.ingredients} aria-labelledby={headingId}>
      <h2 id={headingId} className={styles.heading}>
        材料
      </h2>
      <FieldErrors messages={errors.section} />
      <ol className={styles.rows}>
        {rows.map((row, index) => (
          <li key={row.key}>
            <IngredientRowFields
              row={row}
              position={index + 1}
              isFirst={index === 0}
              isLast={index === rows.length - 1}
              picking={pickingKey === row.key}
              onTogglePicking={() => setPickingKey((current) => (current === row.key ? null : row.key))}
              nodes={nodes}
              appearance={appearance}
              isSelectable={isSelectable}
              path={row.categoryId === null ? null : pathOf(row.categoryId)}
              errors={errors.rows.get(row.key) ?? NO_ROW_ERRORS}
              onEdit={(edit) => {
                if (edit.kind === 'node') setPickingKey(null)
                onEdit(edit)
              }}
            />
          </li>
        ))}
      </ol>
      <div className={styles.addRow}>
        <button
          className={styles.button}
          type="button"
          disabled={!canAddIngredientRow(rows)}
          onClick={() => onEdit({ kind: 'add' })}
        >
          行を足す
        </button>
        <span className={styles.limit}>({MAX_INGREDIENT_ROWS} 行まで)</span>
      </div>
    </section>
  )
}

const NO_ROW_ERRORS: IngredientRowErrors = {}

function IngredientRowFields({
  row,
  position,
  isFirst,
  isLast,
  picking,
  onTogglePicking,
  nodes,
  appearance,
  isSelectable,
  path,
  errors,
  onEdit,
}: {
  row: CocktailIngredientRow
  /** 行の番号(1 から) */
  position: number
  isFirst: boolean
  isLast: boolean
  picking: boolean
  onTogglePicking: () => void
  nodes: CategoryTreeNode[]
  appearance: CategoryTreeAppearance
  isSelectable: (node: CategoryTreeNode) => boolean
  path: string | null
  errors: IngredientRowErrors
  onEdit: (edit: IngredientEdit) => void
}) {
  const treeId = useId()
  const nodeErrorId = useId()
  const optionalErrorId = useId()
  const name = `${position} 行目`
  const hasNodeErrors = (errors.category_id?.length ?? 0) > 0
  const hasOptionalErrors = (errors.is_optional?.length ?? 0) > 0

  return (
    <fieldset className={styles.ingredientRow}>
      <legend className={formStyles.legend}>{name}</legend>
      <div className={styles.node}>
        <p className={styles.nodePath}>ノード: {path ?? '(未選択)'}</p>
        <button
          className={styles.button}
          type="button"
          aria-label={`${name}のノードを選ぶ`}
          aria-expanded={picking}
          aria-controls={picking ? treeId : undefined}
          aria-describedby={hasNodeErrors ? nodeErrorId : undefined}
          onClick={onTogglePicking}
        >
          ノードを選ぶ
        </button>
      </div>
      <FieldErrors id={nodeErrorId} messages={errors.category_id} />
      {picking && (
        <div id={treeId} className={styles.tree}>
          <CategoryTree
            nodes={nodes}
            label={`${name}のノード`}
            appearance={appearance}
            revealId={row.categoryId}
            selection={{
              mode: 'single',
              selectedId: row.categoryId,
              onSelect: (node) => onEdit({ kind: 'node', key: row.key, categoryId: node.id }),
              isSelectable,
            }}
          />
        </div>
      )}
      <div className={formStyles.row}>
        <IngredientBrandSelect
          categoryId={row.categoryId}
          brandId={row.brandId}
          errors={errors.brand_id}
          onSelect={(brand) => onEdit({ kind: 'brand', key: row.key, brand })}
        />
        <TextField
          label="量"
          errors={errors.amount}
          value={row.amount}
          narrow
          onChange={(amount) => onEdit({ kind: 'amount', key: row.key, amount })}
        />
        <div className={formStyles.checkField}>
          <label className={formStyles.check}>
            <input
              type="checkbox"
              checked={row.isOptional}
              aria-invalid={hasOptionalErrors}
              aria-describedby={hasOptionalErrors ? optionalErrorId : undefined}
              onChange={(event) => onEdit({ kind: 'optional', key: row.key, isOptional: event.target.checked })}
            />
            任意
          </label>
          <FieldErrors id={optionalErrorId} messages={errors.is_optional} />
        </div>
      </div>
      <div className={styles.moves}>
        <button
          className={styles.button}
          type="button"
          aria-label={`${name}を上へ`}
          disabled={isFirst}
          onClick={() => onEdit({ kind: 'move', key: row.key, offset: -1 })}
        >
          上へ
        </button>
        <button
          className={styles.button}
          type="button"
          aria-label={`${name}を下へ`}
          disabled={isLast}
          onClick={() => onEdit({ kind: 'move', key: row.key, offset: 1 })}
        >
          下へ
        </button>
        <button
          className={styles.button}
          type="button"
          aria-label={`${name}を消す`}
          onClick={() => onEdit({ kind: 'remove', key: row.key })}
        >
          消す
        </button>
      </div>
    </fieldset>
  )
}
