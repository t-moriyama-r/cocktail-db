import type { CategoryTreeNode } from './CategoryTree.types'

/** ノードとその子孫すべての ID を集める。木が深くても呼び出しが積み上がらないよう、再帰せずにたどる */
export function collectCategoryTreeIds(nodes: readonly CategoryTreeNode[]): Set<number> {
  const ids = new Set<number>()
  const stack = [...nodes]
  for (let current = stack.pop(); current !== undefined; current = stack.pop()) {
    ids.add(current.id)
    stack.push(...current.children)
  }
  return ids
}
