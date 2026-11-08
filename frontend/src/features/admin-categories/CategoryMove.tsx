import { useMemo, useState } from 'react'
import type { components } from '../../api/schema'
import { useApi } from '../../api/useApi'
import InlineApiView from '../../components/ApiView/InlineApiView'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import { pathTo, roleLabel, subtreeIdsOf, toCategoryTreeNode } from './adminCategoryTree'
import { fetchAdminCategoryTree, saveCategory, type CategorySaveWarning, type SaveCategoryResult } from './categoryApi'
import { categoryFormValuesOf, categoryKeptValuesOf } from './categoryFormValues'
import styles from './CategoryActions.module.css'
import { useMounted } from './useMounted'

type CategoryResource = components['schemas']['CategoryResource']
type AdminTreeItem = components['schemas']['CategoryTreeItemResource']

type Props = {
  /** 移すノード(編集で開いたノード)。根でないもの */
  node: CategoryResource
  /** 移せたときに呼ぶ(画面はカテゴリ木へ戻る) */
  onMoved: (name: string, parentPath: readonly string[], warnings: readonly CategorySaveWarning[]) => void
}

type MoveState = { kind: 'idle' } | { kind: 'moving' } | Exclude<SaveCategoryResult, { kind: 'saved' }>

/**
 * ノードの親の付け替え(#125)。「移す先を選ぶ」を押すと木(管理 API の木)を読み込んで出し、移す先を 1 つ選び、
 * 確かめてから部分木ごと移す(#122 の API)。木はふだん閉じておく(編集のたびに大きな木を出さない・読み込まない)。
 *
 * - 自分と自分の下のノード・今の親は選べない(API も自分の下へは 422 にする)
 * - 移す先の根の役割が今と違うときは、選んだところと確かめるときに「役割が〈なし〉から〈その他〉に変わります」と出す。
 *   役割で、有名な銘柄が必須か任意か・材料の数・量の出し方が変わるため(docs/decisions/admin-category-move-delete.md)
 * - 送るのは、開いたときのノードの値に新しい親を入れたもの。上のフォームで書き換えて保存していない値は送らない
 * - 移せないとき(422)は、API が返す理由(自分の下へは移せない・有名な銘柄が部分木の外になる、など)をそのまま出し、画面に残る
 */
export default function CategoryMove({ node, onMoved }: Props) {
  const [open, setOpen] = useState(false)
  return (
    <section className={styles.section} aria-label="親の付け替え">
      <h2 className={styles.title}>親の付け替え</h2>
      <p className={styles.text}>今の親: {node.path.map((step) => step.name).join(' / ')}</p>
      <div>
        <button className={styles.button} type="button" aria-expanded={open} onClick={() => setOpen((current) => !current)}>
          移す先を選ぶ
        </button>
      </div>
      {open && <MovePicker node={node} onMoved={onMoved} />}
    </section>
  )
}

function MovePicker({ node, onMoved }: Props) {
  const { state, retry } = useApi(fetchAdminCategoryTree)
  return (
    <InlineApiView state={state} onRetry={retry}>
      {({ data }) => <MoveForm node={node} tree={data} onMoved={onMoved} />}
    </InlineApiView>
  )
}

function MoveForm({ node, tree, onMoved }: Props & { tree: readonly AdminTreeItem[] }) {
  const [targetId, setTargetId] = useState<number | null>(null)
  const [move, setMove] = useState<MoveState>({ kind: 'idle' })
  const mounted = useMounted()
  const nodes = useMemo(() => tree.map(toCategoryTreeNode), [tree])
  const unselectable = useMemo(() => subtreeIdsOf(tree, node.id), [tree, node.id])
  const targetPath = useMemo(() => (targetId === null ? null : pathTo(tree, targetId)), [tree, targetId])
  const target = targetPath?.[targetPath.length - 1] ?? null
  const roleChange =
    target !== null && target.root_role !== node.root_role
      ? `役割が〈${roleLabel(node.root_role)}〉から〈${roleLabel(target.root_role)}〉に変わります`
      : null

  const submit = async () => {
    if (targetPath === null || target === null) return
    const parentPath = targetPath.map((step) => step.name)
    const question = [`「${node.name}」を「${parentPath.join(' / ')}」の下へ移します`, roleChange, 'よろしいですか?']
      .filter((sentence) => sentence !== null)
      .join('。')
    if (!window.confirm(question)) return
    setMove({ kind: 'moving' })
    const result = await saveCategory(
      node.id,
      { ...categoryFormValuesOf(node), parentId: target.id },
      categoryKeptValuesOf(node),
    )
    // 待つ間に別の画面へ移っていたら、カテゴリ木へ戻さない
    if (!mounted.current) return
    if (result.kind === 'saved') {
      onMoved(node.name, parentPath, result.warnings)
      return
    }
    setMove(result)
  }

  const currentParentId = node.parent_id

  return (
    <>
      <CategoryTree
        nodes={nodes}
        label="移す先"
        selection={{
          mode: 'single',
          selectedId: targetId,
          onSelect: (picked) => {
            setTargetId(picked.id)
            setMove({ kind: 'idle' })
          },
          isSelectable: (candidate) => !unselectable.has(candidate.id) && candidate.id !== currentParentId,
        }}
      />
      <p className={styles.text}>
        移す先: {targetPath === null ? '(未選択)' : targetPath.map((step) => step.name).join(' / ')}
      </p>
      {roleChange !== null && <p className={styles.caution}>{roleChange}</p>}
      <div>
        <button className={styles.button} type="button" disabled={target === null || move.kind === 'moving'} onClick={() => void submit()}>
          移す
        </button>
      </div>
      {move.kind !== 'idle' && move.kind !== 'moving' && (
        <div className={styles.failure} role="alert">
          <p className={styles.text}>{failureMessage(move)}</p>
          {move.kind === 'invalid' && (
            <ul className={styles.reasons}>
              {Object.values(move.errors)
                .flat()
                .map((message, index) => (
                  // 同じ文が別の項目から 2 度来ることがあるので、並びの位置で区別する
                  <li key={index}>{message}</li>
                ))}
            </ul>
          )}
        </div>
      )}
    </>
  )
}

function failureMessage(failure: Exclude<MoveState, { kind: 'idle' | 'moving' }>): string {
  switch (failure.kind) {
    case 'invalid':
      return '移せませんでした。'
    case 'http-error':
      return `移せませんでした(エラー ${failure.status})。時間をおいて、もう一度お試しください。`
    case 'network-error':
      return '移せませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'
  }
}
