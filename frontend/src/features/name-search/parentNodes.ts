import type { components } from '../../api/schema'

type NodeRef = components['schemas']['NodeRefResource']

/**
 * ノードの「親」の列に出す段。path(根が先頭で、そのノードが末尾)から、根とそのノード自身を除いた祖先を取り、
 * いちばん上の段と、すぐ上の親だけを選ぶ(間の段は省く。docs/decisions/public-detail.md の決まったこと 3)。
 * すぐ上の親がいちばん上の段そのものなら、その 1 段だけ。いちばん上の段のノード(親が根)は、何も出さない
 */
export function parentNodes(path: readonly NodeRef[]): NodeRef[] {
  const ancestors = path.slice(1, -1)
  if (ancestors.length === 0) {
    return []
  }
  const top = ancestors[0]
  const parent = ancestors[ancestors.length - 1]
  return top.id === parent.id ? [top] : [top, parent]
}
