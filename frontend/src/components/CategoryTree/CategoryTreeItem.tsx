import TriStateCheckbox from './TriStateCheckbox'
import type { CategoryTreeNode, CategoryTreeSelection } from './CategoryTree.types'
import styles from './CategoryTree.module.css'

type Props = {
  node: CategoryTreeNode
  openIds: ReadonlySet<number>
  /** 絞り込んでいるときに出すノード。null なら絞り込んでいない */
  visibleIds: ReadonlySet<number> | null
  selection: CategoryTreeSelection
  /** 単一選択のラジオボタンを 1 つの組にする名前 */
  radioName: string
  onToggleOpen: (id: number) => void
  onNameClick?: (node: CategoryTreeNode) => void
}

/** 木の 1 ノードの行と、開いていればその子を出す。畳んでいる子は描かない */
export default function CategoryTreeItem({
  node,
  openIds,
  visibleIds,
  selection,
  radioName,
  onToggleOpen,
  onNameClick,
}: Props) {
  const children = visibleIds === null ? node.children : node.children.filter((child) => visibleIds.has(child.id))
  const isOpen = openIds.has(node.id)
  // disabled だけに頼ると、ブラウザの外から発火した click(支援技術・テスト)で選べてしまうため、押されたときにも確かめる
  const isSelectable = selection.mode === 'single' && selection.isSelectable?.(node) !== false

  const hasChildren = children.length > 0

  // 600 ノードを一度に開いても詰まらないよう、1 行の要素は li・開閉のボタン・選ぶ入力・名前だけにする
  return (
    <li className={styles.item}>
      {hasChildren && (
        <button
          type="button"
          className={styles.toggle}
          aria-expanded={isOpen}
          aria-label={`${node.name}を${isOpen ? '閉じる' : '開く'}`}
          onClick={() => onToggleOpen(node.id)}
        >
          {isOpen ? '▾' : '▸'}
        </button>
      )}
      {selection.mode === 'single' ? (
        <input
          type="radio"
          className={hasChildren ? styles.control : styles.leafControl}
          name={radioName}
          aria-label={node.name}
          checked={selection.selectedId === node.id}
          disabled={!isSelectable}
          onChange={() => {
            if (isSelectable) selection.onSelect(node)
          }}
        />
      ) : (
        <TriStateCheckbox
          className={hasChildren ? styles.control : styles.leafControl}
          label={node.name}
          state={selection.checkStateOf(node)}
          onChange={(checked) => selection.onToggle(node, checked)}
        />
      )}
      {onNameClick === undefined ? (
        node.name
      ) : (
        <button type="button" className={styles.nameLink} onClick={() => onNameClick(node)}>
          {node.name}
        </button>
      )}
      {isOpen && hasChildren && (
        <ul className={styles.children}>
          {children.map((child) => (
            <CategoryTreeItem
              key={child.id}
              node={child}
              openIds={openIds}
              visibleIds={visibleIds}
              selection={selection}
              radioName={radioName}
              onToggleOpen={onToggleOpen}
              onNameClick={onNameClick}
            />
          ))}
        </ul>
      )}
    </li>
  )
}
