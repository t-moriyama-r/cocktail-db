import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import type { components } from '../api/schema'
import { useApi } from '../api/useApi'
import { ADMIN_NEW_CATEGORY_PATH, adminCategoryEditPath } from '../components/AdminLayout/adminPaths'
import InlineApiView from '../components/ApiView/InlineApiView'
import CategoryTree from '../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance } from '../components/CategoryTree/CategoryTree.types'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { fetchAdminCategoryTree } from '../features/admin-categories/categoryApi'
import { roleLabel, toCategoryTreeNode } from '../features/admin-categories/adminCategoryTree'
import { readCategoryTreeNotice } from '../features/admin-categories/categoryTreeNotice'
import styles from './AdminCategoryTree.module.css'

type AdminTreeItem = components['schemas']['CategoryTreeItemResource']

/**
 * 管理画面のカテゴリ木(/admin/categories。#123)。木の部品(#297)の単一選択のモードで、木全体を出す。
 *
 * - 旧に木の画面は無く、カテゴリの一覧とミドルの一覧が別々にあった。新は任意の深さのノードを 1 つの木で出す
 * - 並びは API の順(兄弟の `sort_order` の順)のままで、画面では並べ替えない。兄弟の並べ替えの操作は旧に無いので作らない
 * - ノードの行には、名前・英名(名前の後ろに括弧で)・根なら役割・メジャーかを出す
 * - 開閉・名前での絞り込み(当たったノードの祖先も出す)は木の部品がする
 * - ノードを選ぶと、そのノードの編集フォーム(/admin/categories/:id。#308)を開く。新規は木の上の「新規」から開く
 *   (新規のフォームで「親なし」を選ぶと根を作れる。#310)
 * - ノードを保存してこの画面へ戻ったときは、保存したこと(と保存の後の警告)を木の上に出す
 */
export default function AdminCategoryTree() {
  usePageTitle('カテゴリ木')
  const { state, retry } = useApi(fetchAdminCategoryTree)
  const location = useLocation()
  const navigate = useNavigate()
  // 知らせは画面の state に持ち、履歴の state は消す(「戻る」で戻ったとき、古い知らせを出さないため)
  const [notice] = useState(() => readCategoryTreeNotice(location.state))
  useEffect(() => {
    if (location.state != null) {
      void navigate(location.pathname + location.search, { replace: true, state: null })
    }
  }, [location.state, location.pathname, location.search, navigate])

  return (
    <main className={styles.page}>
      <h1 className={styles.title}>カテゴリ木</h1>
      {notice !== null && (
        <p className={styles.notice} role="status">
          {notice}
        </p>
      )}
      <p>
        <Link to={ADMIN_NEW_CATEGORY_PATH}>新規</Link>
      </p>
      <InlineApiView state={state} onRetry={retry}>
        {({ data }) => <Tree items={data} onSelect={(id) => void navigate(adminCategoryEditPath(id))} />}
      </InlineApiView>
    </main>
  )
}

type TreeProps = {
  items: readonly AdminTreeItem[]
  onSelect: (id: number) => void
}

/** 選ぶとすぐ編集フォームへ移るので、選んだ印は持たない */
function Tree({ items, onSelect }: TreeProps) {
  const nodes = useMemo(() => items.map(toCategoryTreeNode), [items])
  const appearance = useMemo(() => buildAppearance(items), [items])

  return (
    <CategoryTree
      label="カテゴリ木"
      nodes={nodes}
      selection={{ mode: 'single', selectedId: null, onSelect: (node) => onSelect(node.id) }}
      appearance={appearance}
    />
  )
}

/** 名前の後ろに添える注記: 「(英名)」・根なら「役割: …」・「メジャー」か「メジャーでない」 */
function buildAppearance(roots: readonly AdminTreeItem[]): CategoryTreeAppearance {
  // 根の ID → 根の役割。根だけが入るので、入っているかで根かどうかも分かる
  const roleOf = new Map(roots.map((root) => [root.id, root.role]))
  return {
    noteOf: (node) => {
      const parts = [`(${node.name_eng ?? ''})`]
      if (roleOf.has(node.id)) {
        parts.push(`役割: ${roleLabel(roleOf.get(node.id) ?? null)}`)
      }
      parts.push(node.is_major ? 'メジャー' : 'メジャーでない')
      return parts.join('・')
    },
  }
}
