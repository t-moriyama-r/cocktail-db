import type { CategoryTreeNode } from './CategoryTree.types'

/** 木のノードを作る(テストと見本で使う)。指定しないものは、英名なし・メジャー・子なし */
export function buildCategoryTreeNode(
  overrides: Partial<CategoryTreeNode> & Pick<CategoryTreeNode, 'id' | 'name'>,
): CategoryTreeNode {
  return { name_eng: null, is_major: true, children: [], ...overrides }
}
