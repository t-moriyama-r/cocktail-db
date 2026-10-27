import type { CategoryTreeFilterResult, CategoryTreeNode } from './CategoryTree.types'
import { collectCategoryTreeIds } from './collectCategoryTreeIds'

/**
 * 名前・英名が入力で始まるノードを探し、出すノードと開くノードを返す。入力が空なら null(絞り込まない)。
 *
 * 当たり方は旧の管理画面の頭文字での絞り込み(本番のビルドの Helper.narrow)に合わせる。
 * 旧は前方一致で、カタカナとひらがな、全角と半角の英数字、英名の大文字と小文字を区別しない。
 * 当たったノードだけを出すと、木のどこにあるのかが分からず、子を選ぶこともできないため、
 * 祖先と子孫も出す。子孫は畳んだまま出し、祖先は開いて当たったノードが見えるようにする。
 */
export function filterCategoryTree(
  nodes: readonly CategoryTreeNode[],
  query: string,
): CategoryTreeFilterResult | null {
  if (query === '') return null

  const visibleIds = new Set<number>()
  const openIds = new Set<number>()
  const visit = (node: CategoryTreeNode, ancestorIds: readonly number[]) => {
    if (matchesCategoryName(node, query)) {
      for (const id of ancestorIds) {
        visibleIds.add(id)
        openIds.add(id)
      }
      for (const id of collectCategoryTreeIds([node])) visibleIds.add(id)
    }
    const childAncestorIds = [...ancestorIds, node.id]
    for (const child of node.children) visit(child, childAncestorIds)
  }
  for (const node of nodes) visit(node, [])

  return { visibleIds, openIds }
}

function matchesCategoryName(node: CategoryTreeNode, query: string): boolean {
  // 旧の Helper.narrow は、日本語名は大文字と小文字をそのまま比べ、英名だけ小文字にそろえて比べる
  if (toComparable(node.name).startsWith(toComparable(query))) return true
  if (node.name_eng === null) return false
  return toComparable(node.name_eng).toLowerCase().startsWith(toComparable(query).toLowerCase())
}

/** 全角の英数字を半角に、カタカナをひらがなにそろえる(旧の Helper.katakanaToHiragana) */
function toComparable(text: string): string {
  return text
    .replace(/[Ａ-Ｚａ-ｚ０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0))
    .replace(/[ァ-ヶ]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0x60))
}

