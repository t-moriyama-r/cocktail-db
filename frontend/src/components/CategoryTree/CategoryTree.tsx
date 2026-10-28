import { useId, useMemo, useState } from 'react'
import CategoryTreeItem from './CategoryTreeItem'
import type { CategoryTreeNode, CategoryTreeSelection } from './CategoryTree.types'
import { collectCategoryTreeIds } from './collectCategoryTreeIds'
import { filterCategoryTree } from './filterCategoryTree'
import styles from './CategoryTree.module.css'

type Props = {
  /** 木の根のノード(複数可) */
  nodes: CategoryTreeNode[]
  selection: CategoryTreeSelection
  /** 渡すと、ノードの名前を押せるボタンにし、押されたノードを渡す */
  onNameClick?: (node: CategoryTreeNode) => void
  /** 木全体の名前(読み上げに使う) */
  label: string
}

/**
 * カテゴリ木を、開閉・名前での絞り込み・選択(単一・複数)のできる形で出す。
 * 選択の状態は持たず、外から受け取った状態を出して、操作を外へ返す。
 *
 * 木は 600 ノードほどあるので、畳んだノードの子は描かない。絞り込んでいる間は、
 * 当たったノードが見えるよう祖先を開き直す(開閉はそのあとも手で変えられる)。
 */
export default function CategoryTree({ nodes, selection, onNameClick, label }: Props) {
  const rootIds = useMemo(() => new Set(nodes.map((node) => node.id)), [nodes])
  const [query, setQuery] = useState('')
  // null は「まだ開閉を変えていない」。既定の開き方を根から導くので、nodes を後から受け取っても根が開く
  const [changedOpenIds, setOpenIds] = useState<ReadonlySet<number> | null>(null)
  const openIds = changedOpenIds ?? rootIds
  const filter = useMemo(() => filterCategoryTree(nodes, query), [nodes, query])
  const radioName = useId()

  const changeQuery = (next: string) => {
    setQuery(next)
    setOpenIds(filterCategoryTree(nodes, next)?.openIds ?? null)
  }
  const toggleOpen = (id: number) => {
    setOpenIds((current) => {
      const next = new Set(current ?? rootIds)
      if (!next.delete(id)) next.add(id)
      return next
    })
  }

  const visibleNodes = filter === null ? nodes : nodes.filter((node) => filter.visibleIds.has(node.id))

  return (
    <div className={styles.root}>
      <div className={styles.toolbar}>
        <input
          type="search"
          className={styles.search}
          aria-label="名前で絞り込む"
          placeholder="名前・英名の頭文字で絞り込む"
          value={query}
          onChange={(event) => changeQuery(event.target.value)}
        />
        <button type="button" className={styles.toolButton} onClick={() => setOpenIds(collectCategoryTreeIds(nodes))}>
          すべて開く
        </button>
        <button type="button" className={styles.toolButton} onClick={() => setOpenIds(new Set())}>
          すべて閉じる
        </button>
      </div>
      {visibleNodes.length === 0 ? (
        <p className={styles.empty}>当たるものがありません</p>
      ) : (
        <ul className={styles.tree} aria-label={label}>
          {visibleNodes.map((node) => (
            <CategoryTreeItem
              key={node.id}
              node={node}
              openIds={openIds}
              visibleIds={filter?.visibleIds ?? null}
              selection={selection}
              radioName={radioName}
              onToggleOpen={toggleOpen}
              onNameClick={onNameClick}
            />
          ))}
        </ul>
      )}
    </div>
  )
}

