import type { components } from '../../api/schema'
import type { CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'

type AdminTreeItem = components['schemas']['CategoryTreeItemResource']
type CategoryRole = components['schemas']['CategoryRole']

/** 根の役割の出し方。役割の無い根(null)は旧の種別 1〜3(蒸留酒・醸造酒・混成酒) */
const ROLE_LABEL: Readonly<Record<CategoryRole | 'none', string>> = {
  none: 'なし',
  soft_drink: 'ソフトドリンク',
  other: 'その他',
}

/** 根の役割の名前。役割の無い根(とその部分木)は「なし」 */
export const roleLabel = (role: CategoryRole | null): string => ROLE_LABEL[role ?? 'none']

/** 根が持てる役割の並び(なし・ソフトドリンク・その他。docs/decisions/category-root-role.md の決まったこと 6) */
export const ROLE_OPTIONS: readonly (CategoryRole | null)[] = [null, 'soft_drink', 'other']

/** 管理 API の木(GET /api/admin/categories)のノードを、木の部品が受け取る形にする */
export function toCategoryTreeNode(item: AdminTreeItem): CategoryTreeNode {
  return {
    id: item.id,
    name: item.name,
    name_eng: item.name_eng,
    is_major: item.is_major,
    children: item.children.map(toCategoryTreeNode),
  }
}

/** 根から id のノードまでのノード(根を含む)。木に無ければ null */
export function pathTo(nodes: readonly AdminTreeItem[], id: number): AdminTreeItem[] | null {
  for (const node of nodes) {
    if (node.id === id) return [node]
    const found = pathTo(node.children, id)
    if (found !== null) return [node, ...found]
  }
  return null
}

/** id のノードと、その下のノードすべての ID。木に無ければ空 */
export function subtreeIdsOf(nodes: readonly AdminTreeItem[], id: number): ReadonlySet<number> {
  const path = pathTo(nodes, id)
  const ids = new Set<number>()
  const collect = (node: AdminTreeItem) => {
    ids.add(node.id)
    node.children.forEach(collect)
  }
  if (path !== null) collect(path[path.length - 1])
  return ids
}
