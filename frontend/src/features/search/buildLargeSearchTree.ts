import type { SelectionTreeNode } from './selection'

/** buildLargeSearchTree が作る木の大きさ */
export const LARGE_SEARCH_TREE_SIZE = {
  roots: 4,
  categories: 80,
  total: 600,
} as const

/**
 * テストで使う、実データと同じ大きさ(約 600 ノード)の木。根 4・その下のカテゴリ 80・残りがミドル。
 * 旧の木と同じ 3 段で、カテゴリの 5 つに 1 つは子が無く、ほかのカテゴリにミドルを順に配る(1 つあたり 8〜9)。
 * URL の長さを厳しめに見るため、ID は 5 桁(10001 から)にしている
 */
export function buildLargeSearchTree(): SelectionTreeNode[] {
  let nextId = 10001
  const roots = Array.from({ length: LARGE_SEARCH_TREE_SIZE.roots }, () => ({ id: nextId++, children: [] as SelectionTreeNode[] }))
  const categories = Array.from({ length: LARGE_SEARCH_TREE_SIZE.categories }, (_, i) => {
    const category = { id: nextId++, children: [] as SelectionTreeNode[] }
    roots[i % roots.length].children.push(category)
    return category
  })
  const parents = categories.filter((_, i) => i % 5 !== 0)
  const middleCount = LARGE_SEARCH_TREE_SIZE.total - LARGE_SEARCH_TREE_SIZE.roots - LARGE_SEARCH_TREE_SIZE.categories
  for (let i = 0; i < middleCount; i++) {
    parents[i % parents.length].children.push({ id: nextId++, children: [] })
  }
  return roots
}
