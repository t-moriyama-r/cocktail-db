import { useMemo, useState } from 'react'
import type { components } from '../api/schema'
import { api } from '../api/client'
import { useApi } from '../api/useApi'
import InlineApiView from '../components/ApiView/InlineApiView'
import CategoryTree from '../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance, CategoryTreeNode } from '../components/CategoryTree/CategoryTree.types'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'

type AdminTreeItem = components['schemas']['CategoryTreeItemResource']
type CategoryRole = components['schemas']['CategoryRole']

const fetchCategoryTree = () => api.GET('/admin/categories')

/** 根の役割の出し方。役割の無い根(null)は旧の種別 1〜3(蒸留酒・醸造酒・混成酒) */
const ROLE_LABEL: Record<CategoryRole | 'none', string> = {
  none: 'なし',
  soft_drink: 'ソフトドリンク',
  other: 'その他',
}

/**
 * 管理画面のカテゴリ木(/admin/categories。#123)。木の部品(#297)の単一選択のモードで、木全体を出す。
 *
 * - 旧に木の画面は無く、カテゴリの一覧とミドルの一覧が別々にあった。新は任意の深さのノードを 1 つの木で出す
 * - 並びは API の順(兄弟の `sort_order` の順)のままで、画面では並べ替えない。兄弟の並べ替えの操作は旧に無いので作らない
 * - ノードの行には、名前・英名(名前の後ろに括弧で)・根なら役割・メジャーかを出す
 * - 開閉・名前での絞り込み(当たったノードの祖先も出す)は木の部品がする
 * - ノードを選ぶと、選んだノードの印が付く。選んだノードの編集フォームを開くのは、フォームの issue(#308)でつなぐ
 */
export default function AdminCategoryTree() {
  usePageTitle('カテゴリ木')
  const { state, retry } = useApi(fetchCategoryTree)
  const [selectedId, setSelectedId] = useState<number | null>(null)

  return (
    <main>
      <h1>カテゴリ木</h1>
      <InlineApiView state={state} onRetry={retry}>
        {({ data }) => <Tree items={data} selectedId={selectedId} onSelect={setSelectedId} />}
      </InlineApiView>
    </main>
  )
}

type TreeProps = {
  items: readonly AdminTreeItem[]
  selectedId: number | null
  onSelect: (id: number) => void
}

function Tree({ items, selectedId, onSelect }: TreeProps) {
  const nodes = useMemo(() => items.map(toCategoryTreeNode), [items])
  const appearance = useMemo(() => buildAppearance(items), [items])

  return (
    <CategoryTree
      label="カテゴリ木"
      nodes={nodes}
      selection={{ mode: 'single', selectedId, onSelect: (node) => onSelect(node.id) }}
      appearance={appearance}
    />
  )
}

/** 管理 API のノードを、木の部品が受け取る形にする */
function toCategoryTreeNode(item: AdminTreeItem): CategoryTreeNode {
  return {
    id: item.id,
    name: item.name,
    name_eng: item.name_eng,
    is_major: item.is_major,
    children: item.children.map(toCategoryTreeNode),
  }
}

/** 名前の後ろに添える注記: 「(英名)」・根なら「役割: …」・「メジャー」か「メジャーでない」 */
function buildAppearance(roots: readonly AdminTreeItem[]): CategoryTreeAppearance {
  // 根の ID → 根の役割。根だけが入るので、入っているかで根かどうかも分かる
  const roleOf = new Map(roots.map((root) => [root.id, root.role]))
  return {
    noteOf: (node) => {
      const parts = [`(${node.name_eng ?? ''})`]
      if (roleOf.has(node.id)) {
        parts.push(`役割: ${ROLE_LABEL[roleOf.get(node.id) ?? 'none']}`)
      }
      parts.push(node.is_major ? 'メジャー' : 'メジャーでない')
      return parts.join('・')
    },
  }
}
